<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Jobs\SendExpoPush;
use App\Models\AppDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\AppCrewReminders;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The staff app's API (/api/app/…): signing in with the ERP account, the trips
 * operations work from, putting a car and a driver on one, and the phone
 * notifications about trips still without both inside the 12 hours.
 */
final class StaffAppApiTest extends TestCase
{
    use DatabaseMigrations;

    private const ADDRESS = 'Secret Tower, Road 1234, Seef';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 08:00:00');
        app(ModuleManager::class)->install('limousine');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (glob(storage_path('app/workspaces') . '/*.sqlite') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function staff(array $attributes = [], bool $locked = false): User
    {
        $user = User::factory()->create(array_merge(['is_admin' => true, 'password' => Hash::make('right-password')], $attributes));
        if ($locked) {
            $user->forceFill(['trip_details_locked' => true])->save();
        }

        return $user->fresh() ?? $user;
    }

    private function signIn(User $user): void
    {
        // A real token, as the app holds one: the request goes the whole way
        // through the token check on Main and into the right database.
        $this->app["auth"]->forgetGuards();
        $this->withToken($user->createToken("test")->plainTextToken);
    }

    private function trip(string $startAt = '2026-11-01 18:00:00'): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Ministry Travel Desk']);
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/' . random_int(10000, 99999),
            'customer_id' => $customer->id,
            'pickup_at' => $startAt,
            'status' => LimoBooking::STATUS_CONFIRMED,
        ]);
        $booking->forceFill(['pax_name' => 'Mr Tan', 'pax_contact' => '39112233'])->saveQuietly();

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '30777',
            'status' => LimoLeg::STATUS_CONFIRMED,
            'start_at' => $startAt,
            'from_location' => self::ADDRESS,
            'to_location' => 'Bahrain Airport',
            'rate' => 12, 'net_amount' => 12,
        ]);
        $leg->forceFill(['from_country' => 'BH', 'to_country' => 'BH'])->saveQuietly();

        return $leg->fresh() ?? $leg;
    }

    private function car(): Vehicle
    {
        $car = new Vehicle();
        $car->forceFill(['name' => 'Mercedes E-Class', 'plate_no' => '54321', 'status' => 'available', 'active' => true])->save();

        return $car;
    }

    private function driver(): LimoDriver
    {
        $driver = new LimoDriver();
        $driver->forceFill(['name' => 'Rashid', 'active' => true])->save();

        return $driver;
    }

    // ── The stores' public pages ─────────────────────────────────────

    public function test_the_privacy_policy_and_account_deletion_pages_are_public(): void
    {
        $this->get("/staff-app/privacy")->assertOk()->assertSee("Privacy Policy")->assertSee("سياسة الخصوصية");
        $this->get("/staff-app/account-deletion")->assertOk()->assertSee("Request deletion")->assertSee("mailto:", false);
    }

    // ── Signing in ──────────────────────────────────────────────────

    public function test_signing_in_returns_a_token_that_opens_the_api(): void
    {
        $user = $this->staff(['email' => 'abbas@example.test']);

        $token = $this->postJson('/api/app/login', ['login' => 'abbas@example.test', 'password' => 'right-password', 'device_name' => 'Abbas iPhone'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->json('token');

        $this->assertIsString($token);

        $this->withToken($token)->getJson('/api/app/me')
            ->assertOk()
            ->assertJsonPath('user.name', $user->name)
            ->assertJsonPath('can.view_trips', true);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->staff(['email' => 'abbas@example.test']);

        $this->postJson('/api/app/login', ['login' => 'abbas@example.test', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_an_arabic_phone_is_answered_in_arabic(): void
    {
        $this->staff(["email" => "abbas@example.test"]);

        $this->withHeader("Accept-Language", "ar")
            ->postJson("/api/app/login", ["login" => "abbas@example.test", "password" => "wrong"])
            ->assertStatus(422)
            ->assertJsonPath("errors.login.0", "بيانات الدخول هذه غير صحيحة.");
    }

    public function test_one_account_is_locked_after_ten_wrong_passwords_from_anywhere(): void
    {
        $this->staff(['email' => 'abbas@example.test']);

        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.' . $i])
                ->postJson('/api/app/login', ['login' => 'abbas@example.test', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        // An eleventh address, and even the right password: locked for now.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/app/login', ['login' => 'abbas@example.test', 'password' => 'right-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_a_paused_account_cannot_sign_in(): void
    {
        $this->staff(['email' => 'abbas@example.test', 'is_paused' => true]);

        $this->postJson('/api/app/login', ['login' => 'abbas@example.test', 'password' => 'right-password'])
            ->assertStatus(422);
    }

    public function test_signing_out_ends_the_token(): void
    {
        $this->staff(['email' => 'abbas@example.test']);
        $token = (string) $this->postJson('/api/app/login', ['login' => 'abbas@example.test', 'password' => 'right-password'])->json('token');

        $this->withToken($token)->postJson('/api/app/logout')->assertOk();

        // A fresh app instance, as a new request would be.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/app/me')->assertStatus(401);
    }

    public function test_nothing_opens_without_a_token(): void
    {
        $this->getJson('/api/app/me')->assertStatus(401);
        $this->getJson('/api/app/trips')->assertStatus(401);
    }

    // ── Trips ───────────────────────────────────────────────────────

    public function test_operations_see_the_trips_waiting_for_a_crew(): void
    {
        $this->signIn($this->staff());
        $leg = $this->trip();

        $this->getJson('/api/app/trips?scope=needs_crew')
            ->assertOk()
            ->assertJsonPath('data.0.id', $leg->id)
            ->assertJsonPath('data.0.missing', 'car_and_driver')
            ->assertJsonPath('data.0.urgency', 'due')
            ->assertJsonPath('data.0.pickup', self::ADDRESS);
    }

    public function test_a_locked_operations_user_gets_the_short_version_and_nothing_to_copy(): void
    {
        $this->signIn($this->staff(locked: true));
        $leg = $this->trip();

        $this->getJson('/api/app/trips/' . $leg->id)
            ->assertOk()
            ->assertJsonPath('data.details_hidden', true)
            ->assertJsonPath('data.pickup', '🔒 Bahrain')
            ->assertJsonPath('data.passenger_phone', null)
            ->assertJsonPath('data.copy_text', '');
    }

    public function test_assigning_from_the_phone_starts_the_trip_and_shows_the_details(): void
    {
        $this->signIn($this->staff(locked: true));
        $leg = $this->trip();
        $car = $this->car();
        $driver = $this->driver();

        $this->getJson('/api/app/trips/' . $leg->id . '/crew-options')
            ->assertOk()
            ->assertJsonPath('cars.0.value', $car->id)
            ->assertJsonPath('drivers.0.value', $driver->id);

        $this->postJson('/api/app/trips/' . $leg->id . '/assign', ['car_id' => $car->id, 'driver_id' => $driver->id])
            ->assertOk()
            ->assertJsonPath('started', true)
            ->assertJsonPath('data.details_hidden', false)
            ->assertJsonPath('data.pickup', self::ADDRESS);

        $this->assertSame(LimoLeg::STATUS_ACTIVE, $leg->fresh()?->status);
    }

    public function test_a_driver_without_a_car_is_refused_with_the_reason(): void
    {
        $this->signIn($this->staff());
        $leg = $this->trip();

        $this->postJson('/api/app/trips/' . $leg->id . '/assign', ['driver_id' => $this->driver()->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('car_id');

        $this->assertSame(LimoLeg::STATUS_CONFIRMED, $leg->fresh()?->status);
    }

    public function test_someone_without_limousine_access_is_refused(): void
    {
        $this->signIn($this->staff(['is_admin' => false]));
        $leg = $this->trip();

        $this->getJson('/api/app/trips')->assertStatus(403);
        $this->postJson('/api/app/trips/' . $leg->id . '/assign', ['car_id' => $this->car()->id])->assertStatus(403);
    }

    // ── Phones ──────────────────────────────────────────────────────

    public function test_a_phone_registers_for_notifications_and_signs_off(): void
    {
        $user = $this->staff();
        $this->signIn($user);

        $this->postJson('/api/app/devices', ['token' => 'ExponentPushToken[abc123]', 'platform' => 'ios'])->assertOk();
        $this->assertSame(['ExponentPushToken[abc123]'], AppDevice::tokensFor([$user->id]));

        $this->postJson('/api/app/devices', ['token' => 'not-a-push-token'])->assertStatus(422);

        $this->deleteJson('/api/app/devices', ['token' => 'ExponentPushToken[abc123]'])->assertOk();
        $this->assertSame([], AppDevice::tokensFor([$user->id]));
    }

    // ── The business database ───────────────────────────────────────

    public function test_staff_of_one_business_work_in_its_database(): void
    {
        $owner = $this->staff(['email' => 'owner@erp.test', 'is_super_admin' => true]);
        $manager = app(WorkspaceManager::class);
        $workspace = $manager->provision('Wanaan', $owner, ['limousine']);

        // Locked to that business, with its copy of the account inside.
        $staff = $this->staff(['email' => 'abbas@example.test', 'home_workspace_id' => $workspace->id]);
        $manager->withTenant((string) $workspace->databasePath(), function (): void {
            $this->staff(['email' => 'abbas@example.test']);
            $this->trip();
        });

        $this->signIn($staff);

        $this->getJson('/api/app/me')
            ->assertOk()
            ->assertJsonPath('workspace.id', $workspace->id);

        $this->getJson('/api/app/trips?scope=needs_crew')
            ->assertOk()
            ->assertJsonPath('data.0.reference', '30777');
    }

    // ── Notifications ───────────────────────────────────────────────

    public function test_operations_phones_are_reminded_every_10_minutes_and_the_owner_once(): void
    {
        $operations = $this->staff(locked: true);
        $owner = $this->staff(['is_super_admin' => true]);
        AppDevice::query()->create(['user_id' => $operations->id, 'token' => 'ExponentPushToken[ops]']);
        AppDevice::query()->create(['user_id' => $owner->id, 'token' => 'ExponentPushToken[owner]']);
        $leg = $this->trip();
        $leg->forceFill(['created_at' => '2026-10-30 10:00:00'])->saveQuietly();

        Bus::fake();
        $reminders = app(AppCrewReminders::class);

        $this->assertSame(['reminders' => 1, 'alerts' => 1], $reminders->run(Carbon::now()));
        Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => ($job->messages[0]['to'] ?? null) === 'ExponentPushToken[ops]'
            && ($job->messages[0]['data']['trip_id'] ?? null) === $leg->id);
        Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => ($job->messages[0]['to'] ?? null) === 'ExponentPushToken[owner]');

        Carbon::setTestNow('2026-11-01 08:05:00');
        $this->assertSame(['reminders' => 0, 'alerts' => 0], $reminders->run(Carbon::now()));

        Carbon::setTestNow('2026-11-01 08:10:00');
        $this->assertSame(['reminders' => 1, 'alerts' => 0], $reminders->run(Carbon::now()));

        // Crewed: quiet.
        $leg->forceFill(['car_id' => $this->car()->id, 'driver_id' => $this->driver()->id])->saveQuietly();
        Carbon::setTestNow('2026-11-01 08:20:00');
        $this->assertSame(['reminders' => 0, 'alerts' => 0], $reminders->run(Carbon::now()));
    }

    public function test_without_a_phone_nobody_is_notified(): void
    {
        $this->staff(locked: true);
        $this->trip();
        Bus::fake();

        $this->assertSame(['reminders' => 0, 'alerts' => 0], app(AppCrewReminders::class)->run(Carbon::now()));
        Bus::assertNotDispatched(SendExpoPush::class);
    }

    public function test_a_phone_that_uninstalled_the_app_is_forgotten(): void
    {
        $user = $this->staff();
        AppDevice::query()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[gone]']);
        AppDevice::query()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[kept]']);

        Http::fake([SendExpoPush::ENDPOINT => Http::response(['data' => [
            ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'ok', 'id' => 'ticket-1'],
        ]])]);

        (new SendExpoPush([
            ['to' => 'ExponentPushToken[gone]', 'title' => 't', 'body' => 'b'],
            ['to' => 'ExponentPushToken[kept]', 'title' => 't', 'body' => 'b'],
        ]))->handle(app(\Illuminate\Http\Client\Factory::class));

        $this->assertSame(['ExponentPushToken[kept]'], AppDevice::tokensFor([$user->id]));
    }
}

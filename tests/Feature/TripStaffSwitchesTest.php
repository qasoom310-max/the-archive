<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Jobs\SendExpoPush;
use App\Livewire\Settings\UserManager;
use App\Models\AppDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\AppCrewReminders;
use Tests\TestCase;

/**
 * The owner's two switches on a person under Settings → Users: the trip-details
 * lock, and the staff-app trip reminders — and the 2026-11-01 change that
 * opened Abbas and Ali again while keeping their reminders.
 */
final class TripStaffSwitchesTest extends TestCase
{
    use DatabaseMigrations;

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

    private function owner(): User
    {
        return User::factory()->create(['email' => 'owner@erp.test', 'is_admin' => true, 'is_super_admin' => true]);
    }

    /** A trip inside the 12 hours with neither a car nor a driver. */
    private function waitingTrip(): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Ministry Travel Desk']);
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00901',
            'customer_id' => $customer->id,
            'pickup_at' => '2026-11-01 18:00:00',
            'status' => LimoBooking::STATUS_CONFIRMED,
        ]);

        return LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '30901',
            'status' => LimoLeg::STATUS_CONFIRMED,
            'start_at' => '2026-11-01 18:00:00',
            'from_location' => 'Seef',
            'to_location' => 'Bahrain Airport',
            'rate' => 12, 'net_amount' => 12,
        ]);
    }

    public function test_the_owner_locks_and_opens_a_person_in_every_database(): void
    {
        $owner = $this->owner();
        $manager = app(WorkspaceManager::class);
        $workspace = $manager->provision('Wanaan', $owner, ['limousine']);
        $path = (string) $workspace->databasePath();

        $ali = User::factory()->create(['name' => 'Ali Mak', 'email' => 'ali@example.test']);
        $manager->withTenant($path, static function (): void {
            User::factory()->create(['name' => 'Ali Mak', 'email' => 'ali@example.test']);
        });
        $lockedInBusiness = static fn (): bool => $manager->withTenant(
            $path,
            static fn (): bool => User::query()->where('email', 'ali@example.test')->sole()->seesTripDetailsOnlyWhenCrewed(),
        );

        $this->actingAs($owner);
        Livewire::test(UserManager::class)
            ->assertSee('Lock trip details')
            ->call('toggleTripLock', $ali->id)
            ->assertSee('Trip details locked')
            ->assertSee('Open trip details');

        $this->assertTrue($ali->refresh()->trip_details_locked);
        $this->assertTrue($lockedInBusiness());

        Livewire::test(UserManager::class)->call('toggleTripLock', $ali->id);

        $this->assertFalse($ali->refresh()->trip_details_locked);
        $this->assertFalse($lockedInBusiness());
    }

    public function test_only_the_owner_has_the_switches(): void
    {
        $staff = User::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(UserManager::class)
            ->assertDontSee('Lock trip details')
            ->call('toggleTripLock', $staff->id)
            ->assertForbidden();

        $this->assertFalse((bool) $staff->fresh()?->trip_details_locked);
    }

    public function test_an_owner_is_never_locked(): void
    {
        $other = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($this->owner());

        Livewire::test(UserManager::class)->call('toggleTripLock', $other->id);

        $this->assertFalse((bool) $other->fresh()?->trip_details_locked);
    }

    public function test_reminders_follow_their_own_switch_not_the_lock(): void
    {
        $reminded = User::factory()->create(['email' => 'abbas@example.test']);
        $lockedOnly = User::factory()->create(['email' => 'locked@example.test']);
        $lockedOnly->forceFill(['trip_details_locked' => true])->save();
        $paused = User::factory()->create(['email' => 'paused@example.test', 'is_paused' => true]);
        $paused->forceFill(['trip_reminders' => true])->save();

        $this->actingAs($this->owner());
        Livewire::test(UserManager::class)
            ->call('toggleTripReminders', $reminded->id)
            ->assertSee('Turn off trip reminders');
        $this->assertTrue($reminded->refresh()->trip_reminders);
        $this->assertFalse($reminded->trip_details_locked);

        foreach ([$reminded, $lockedOnly, $paused] as $user) {
            AppDevice::query()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[' . $user->email . ']']);
        }
        $this->waitingTrip();

        Bus::fake();
        app(AppCrewReminders::class)->run(Carbon::now());

        $sentTo = Bus::dispatched(SendExpoPush::class)
            ->flatMap(static fn (SendExpoPush $job): array => array_column($job->messages, 'to'))
            ->all();
        $this->assertSame(['ExponentPushToken[abbas@example.test]'], $sentTo);
    }

    public function test_abbas_and_ali_are_opened_again_and_keep_their_reminders(): void
    {
        $abbas = User::factory()->create(['name' => 'Abbas Hamdan', 'email' => 'hamdanabbas98@gmail.com']);
        $ali = User::factory()->create(['name' => 'Ali Makki', 'email' => 'ali.makki@example.test']);
        $yousif = User::factory()->create(['name' => 'Yousif', 'email' => 'yousif@example.test']);
        foreach ([$abbas, $ali, $yousif] as $user) {
            $user->forceFill(['trip_details_locked' => true, 'trip_reminders' => false])->save();
        }

        $migration = include base_path('database/migrations/2026_11_01_100001_open_trip_details_and_split_trip_reminders.php');
        $migration->up();

        $this->assertFalse((bool) $abbas->fresh()?->trip_details_locked);
        $this->assertFalse((bool) $ali->fresh()?->trip_details_locked);
        // Anyone else the owner has locked since stays locked.
        $this->assertTrue((bool) $yousif->fresh()?->trip_details_locked);

        foreach ([$abbas, $ali, $yousif] as $user) {
            $this->assertTrue((bool) $user->fresh()?->trip_reminders, $user->name . ' keeps the reminders');
        }
    }
}

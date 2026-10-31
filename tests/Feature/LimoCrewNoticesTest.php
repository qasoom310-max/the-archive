<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Livewire\LimoCrewAlertSettings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCrewAlertConfiguration;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\CrewNotices;
use Modules\Rental\Models\Vehicle;
use Modules\WhatsApp\Jobs\SendWhatsAppMessage;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Modules\WhatsApp\Services\WhatsAppService;
use Tests\TestCase;

/**
 * The customer is promised their driver and car 12 hours before pick-up.
 *
 * Operations kept arranging trips off the system, so the details were never in
 * it and customers rang (at 5:30 in the morning) asking who was coming. Now the
 * system sends the customer the details itself the moment a trip inside the 12
 * hours has both a car and a driver, reminds operations every 10 minutes until
 * it does, and tells the owner once when the promise is missed.
 */
final class LimoCrewNoticesTest extends TestCase
{
    use DatabaseMigrations;

    private const NOW = '2026-11-01 08:00:00';

    private const OPERATIONS = '97333000001';

    private const OWNER = '97333000009';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('whatsapp');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (glob(storage_path('app/workspaces') . '/*.sqlite') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function switchOn(): void
    {
        WhatsAppConfiguration::query()->create([
            'phone_number_id' => '100000000000001',
            'business_account_id' => '200000000000002',
            'access_token' => 'EAAG-secret-token',
            'app_secret' => 'app-secret-abc',
            'webhook_verify_token' => 'verify-me-123',
            'api_version' => 'v21.0',
            'template_language' => 'en',
            'enabled' => true,
        ]);

        LimoCrewAlertConfiguration::query()->create([
            'enabled' => true,
            'operations_numbers' => "+973 3300 0001\n",
            'owner_number' => '33000009',
        ]);
    }

    private function trip(string $startAt, bool $crewed = false, string $paxContact = '', ?string $createdAt = null, ?string $reference = null): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Ministry Travel Desk', 'phone' => '17000000']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/' . random_int(10000, 99999),
            'customer_id' => $customer->id,
            'pickup_at' => $startAt,
            'status' => LimoBooking::STATUS_CONFIRMED,
        ]);
        $booking->forceFill(['pax_name' => 'Mr Tan', 'pax_contact' => $paxContact])->saveQuietly();

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => $reference ?? (string) random_int(10000, 99999),
            'status' => LimoLeg::STATUS_CONFIRMED,
            'start_at' => $startAt,
            'from_location' => 'Hotel',
            'to_location' => 'Bahrain Airport',
            'rate' => 12, 'net_amount' => 12,
        ]);

        if ($crewed) {
            $leg->forceFill(['car_id' => $this->car()->id, 'driver_id' => $this->driver()->id])->saveQuietly();
        }

        if ($createdAt !== null) {
            $leg->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $leg->fresh() ?? $leg;
    }

    private function car(string $name = 'Mercedes E-Class'): Vehicle
    {
        $car = new Vehicle();
        $car->forceFill(['name' => $name, 'plate_no' => '54321', 'color' => 'Black', 'make' => 'Mercedes', 'model' => 'E-Class', 'status' => 'available'])->save();

        return $car;
    }

    private function driver(string $name = 'Rashid'): LimoDriver
    {
        $driver = new LimoDriver();
        $driver->forceFill(['name' => $name, 'phone' => '+973 3999 8888', 'active' => true])->save();

        return $driver;
    }

    /** @return array{customers: int, reminders: int, alerts: int} */
    private function sendNow(): array
    {
        return app(CrewNotices::class)->run(Carbon::now());
    }

    /** @return list<string> the values the job would fill the template with */
    private static function variables(SendWhatsAppMessage $job): array
    {
        $template = $job->payload['template'] ?? [];
        $components = is_array($template) ? ($template['components'] ?? []) : [];

        foreach (is_array($components) ? $components : [] as $component) {
            if (is_array($component) && ($component['type'] ?? null) === 'body' && is_array($component['parameters'] ?? null)) {
                return array_values(array_map(
                    static fn (mixed $parameter): string => is_array($parameter) && is_string($parameter['text'] ?? null) ? $parameter['text'] : '',
                    $component['parameters'],
                ));
            }
        }

        return [];
    }

    public function test_the_customer_gets_the_driver_and_car_once_the_trip_is_inside_12_hours(): void
    {
        $this->switchOn();
        Bus::fake();
        $leg = $this->trip('2026-11-01 18:00:00', crewed: true, paxContact: 'Mr Tan 3911 2233');

        $this->assertSame(['customers' => 1, 'reminders' => 0, 'alerts' => 0], $this->sendNow());

        Bus::assertDispatched(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) use ($leg): bool {
            $vars = self::variables($job);

            // The passenger's own number, with Bahrain's code put in front.
            return $job->to === '97339112233'
                && $job->payload['template']['name'] === CrewNotices::TEMPLATE_CUSTOMER
                && $vars[0] === 'Mr Tan'
                && $vars[1] === (string) $leg->reference
                && $vars[3] === 'Rashid'
                && $vars[4] === '+973 3999 8888'
                && $vars[5] === 'Mercedes E-Class (Black)'
                && $vars[6] === '54321';
        });
        $this->assertNotNull($leg->fresh()?->crew_notified_at);
    }

    public function test_without_a_passenger_number_the_customer_gets_it(): void
    {
        $this->switchOn();
        Bus::fake();
        $this->trip('2026-11-01 18:00:00', crewed: true);

        $this->sendNow();

        Bus::assertDispatched(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job): bool => $job->to === '97317000000');
    }

    public function test_nothing_goes_out_for_a_trip_more_than_12_hours_away(): void
    {
        $this->switchOn();
        Bus::fake();
        $this->trip('2026-11-02 09:00:00', crewed: true);
        $this->trip('2026-11-02 09:00:00');

        $this->assertSame(['customers' => 0, 'reminders' => 0, 'alerts' => 0], $this->sendNow());
        Bus::assertNotDispatched(SendWhatsAppMessage::class);
    }

    public function test_the_details_go_once_and_again_only_when_the_driver_changes(): void
    {
        $this->switchOn();
        Bus::fake();
        $leg = $this->trip('2026-11-01 18:00:00', crewed: true);

        $this->sendNow();
        $this->sendNow();
        Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 1);

        $leg->forceFill(['driver_id' => $this->driver('Yousif')->id])->saveQuietly();
        $this->sendNow();

        Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 2);
        Bus::assertDispatched(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job): bool => (self::variables($job)[3] ?? '') === 'Yousif');
    }

    public function test_operations_are_reminded_every_10_minutes_until_the_trip_has_a_car_and_driver(): void
    {
        $this->switchOn();
        Bus::fake();
        $leg = $this->trip('2026-11-01 18:00:00');

        $this->sendNow();
        Bus::assertDispatched(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job): bool {
            return $job->to === self::OPERATIONS
                && $job->payload['template']['name'] === CrewNotices::TEMPLATE_REMINDER
                && (self::variables($job)[3] ?? '') === 'car and driver';
        });

        // Five minutes on: not again yet.
        Carbon::setTestNow('2026-11-01 08:05:00');
        $this->assertSame(0, $this->sendNow()['reminders']);

        // Ten minutes on: again.
        Carbon::setTestNow('2026-11-01 08:10:00');
        $this->assertSame(1, $this->sendNow()['reminders']);

        // Crewed: the reminders stop and the customer is told instead.
        $leg->forceFill(['car_id' => $this->car()->id, 'driver_id' => $this->driver()->id])->saveQuietly();
        Carbon::setTestNow('2026-11-01 08:20:00');
        $this->assertSame(['customers' => 1, 'reminders' => 0, 'alerts' => 0], $this->sendNow());
    }

    public function test_the_reminder_says_what_is_missing(): void
    {
        $this->switchOn();
        Bus::fake();
        $leg = $this->trip('2026-11-01 18:00:00');
        $leg->forceFill(['car_id' => $this->car()->id])->saveQuietly();

        $this->sendNow();

        Bus::assertDispatched(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job): bool => $job->to === self::OPERATIONS
            && (self::variables($job)[3] ?? '') === 'driver');
    }

    public function test_the_owner_is_told_once_when_the_12_hours_are_missed(): void
    {
        $this->switchOn();
        Bus::fake();
        $leg = $this->trip('2026-11-01 18:00:00', createdAt: '2026-10-30 10:00:00');

        $this->assertSame(1, $this->sendNow()['alerts']);
        Bus::assertDispatched(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job): bool => $job->to === self::OWNER);

        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->assertSame(0, $this->sendNow()['alerts']);
        $this->assertNotNull($leg->fresh()?->crew_escalated_at);
    }

    public function test_a_booking_taken_inside_the_12_hours_gets_an_hour_before_the_owner_hears(): void
    {
        $this->switchOn();
        Bus::fake();
        $this->trip('2026-11-01 14:00:00', createdAt: '2026-11-01 07:50:00');

        // Operations hear straight away; the owner does not yet.
        $this->assertSame(['customers' => 0, 'reminders' => 1, 'alerts' => 0], $this->sendNow());

        Carbon::setTestNow('2026-11-01 08:51:00');
        $this->assertSame(1, $this->sendNow()['alerts']);
    }

    public function test_reminders_stop_once_the_pick_up_time_has_passed(): void
    {
        $this->switchOn();
        Bus::fake();
        $this->trip('2026-11-01 07:30:00');

        $this->assertSame(['customers' => 0, 'reminders' => 0, 'alerts' => 0], $this->sendNow());
    }

    public function test_nothing_is_sent_while_it_is_switched_off(): void
    {
        $this->switchOn();
        LimoCrewAlertConfiguration::query()->update(['enabled' => false]);
        Bus::fake();
        $this->trip('2026-11-01 18:00:00', crewed: true);
        $this->trip('2026-11-01 18:00:00');

        $this->assertSame(['customers' => 0, 'reminders' => 0, 'alerts' => 0], $this->sendNow());
        Bus::assertNotDispatched(SendWhatsAppMessage::class);
    }

    public function test_a_cancelled_or_finished_trip_is_left_alone(): void
    {
        $this->switchOn();
        Bus::fake();
        $this->trip('2026-11-01 18:00:00')->forceFill(['status' => LimoLeg::STATUS_CANCELLED])->saveQuietly();
        $this->trip('2026-11-01 18:00:00', crewed: true)->forceFill(['status' => LimoLeg::STATUS_COMPLETED])->saveQuietly();

        $this->assertSame(['customers' => 0, 'reminders' => 0, 'alerts' => 0], $this->sendNow());
    }

    public function test_the_settings_need_both_numbers_before_switching_on(): void
    {
        Livewire::test(LimoCrewAlertSettings::class)
            // No WhatsApp connection in this database yet: the page says so.
            ->assertSeeHtml('data-whatsapp-not-ready')
            ->set('enabled', true)
            ->call('save')
            ->assertHasErrors('operationsNumbers')
            ->set('operationsNumbers', "33000001\n33000002")
            ->call('save')
            ->assertHasErrors('ownerNumber')
            ->set('ownerNumber', '33000009')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $config = LimoCrewAlertConfiguration::current();
        $this->assertTrue($config->enabled);
        $this->assertSame(['97333000001', '97333000002'], $config->operationsNumbers());
        $this->assertSame('97333000009', $config->ownerNumber());
    }

    public function test_the_settings_are_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(LimoCrewAlertSettings::class)->assertForbidden();
    }

    public function test_the_bookings_list_shows_and_counts_the_trips_waiting_for_a_crew(): void
    {
        $this->trip('2026-11-01 18:00:00', reference: '71001');
        $this->trip('2026-11-02 06:00:00', reference: '71002');
        $this->trip('2026-11-01 18:00:00', crewed: true, reference: '71003');
        $this->trip('2026-11-05 10:00:00', reference: '71004');

        Livewire::test(Bookings::class)
            ->call('openTab', 'needs_crew')
            ->assertSet('tab', 'needs_crew')
            ->assertSee('71001')
            ->assertSee('71002')
            ->assertDontSee('71003')
            ->assertDontSee('71004')
            ->assertSeeHtml('data-crew-due')
            ->assertSeeHtml('data-crew-soon')
            ->assertViewHas('needsCrewCount', 2);
    }

    /**
     * The send queue runs on Main, so a message records which database it came
     * from. A scheduled task inside a workspace has no browser cookie — it used
     * to be recorded as Main's, and went out on Main's WhatsApp account.
     */
    public function test_a_message_sent_from_inside_a_workspace_goes_out_on_that_workspace(): void
    {
        $owner = User::factory()->create(['is_admin' => true, 'email' => 'owner@erp.test']);
        $manager = app(WorkspaceManager::class);
        $workspace = $manager->provision('Wanaan', $owner, ['whatsapp']);

        Bus::fake();

        $manager->withTenant((string) $workspace->databasePath(), function (): void {
            WhatsAppConfiguration::query()->create([
                'phone_number_id' => '100000000000009',
                'access_token' => 'EAAG-tenant-token',
                'template_language' => 'en',
                'enabled' => true,
            ]);

            app(WhatsAppService::class)->sendTemplateMessage('97333000001', CrewNotices::TEMPLATE_REMINDER, ['1', '2', '3', '4']);
        });

        Bus::assertDispatched(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job): bool => $job->workspaceId === (int) $workspace->id);
    }
}

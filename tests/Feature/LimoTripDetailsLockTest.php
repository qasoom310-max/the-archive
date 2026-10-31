<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Http\Controllers\ServiceOrderController;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Livewire\LimoCrewAlertSettings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\LimoQueueRows;
use Modules\Limousine\Services\ServiceOrderPdf;
use Modules\Rental\Models\Vehicle;
use Modules\WhatsApp\Assistant\AssistantActions;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Operations staff the owner names see a trip's details only once it has a car
 * and a driver.
 *
 * The office copied trips out of the system and sent them to drivers on
 * WhatsApp without assigning anyone, so the customer — promised the driver and
 * car 12 hours ahead — had nothing to be sent. For a ticked person, until both
 * are assigned, a trip shows its time, car type, customer and countries; the
 * places, passenger, phones, copy text, booking page and service order wait.
 */
final class LimoTripDetailsLockTest extends TestCase
{
    use DatabaseMigrations;

    private const ADDRESS = 'Secret Tower, Road 1234, Seef';

    private const PAX_PHONE = '39112233';

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
    }

    private function operations(bool $locked = true, bool $superAdmin = false): User
    {
        $user = User::factory()->create(['is_admin' => true, 'is_super_admin' => $superAdmin]);
        $user->forceFill(['trip_details_locked' => $locked])->save();
        $this->actingAs($user);

        return $user;
    }

    private function trip(bool $crewed = false): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Ministry Travel Desk', 'phone' => '17000000']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00777',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_CONFIRMED,
        ]);
        $booking->forceFill(['pax_name' => 'Mr Tan', 'pax_contact' => self::PAX_PHONE, 'notes' => 'Gate 4, call on arrival'])->saveQuietly();

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '20777',
            'status' => LimoLeg::STATUS_CONFIRMED,
            'start_at' => now()->addDay(),
            'from_location' => self::ADDRESS,
            'to_location' => 'King Fahd Causeway',
            'rate' => 12, 'net_amount' => 12,
        ]);
        $leg->forceFill(['from_country' => 'BH', 'to_country' => 'SA'])->saveQuietly();

        if ($crewed) {
            $this->crew($leg);
        }

        return $leg->fresh() ?? $leg;
    }

    private function crew(LimoLeg $leg): void
    {
        $car = new Vehicle();
        $car->forceFill(['name' => 'Mercedes E-Class', 'plate_no' => '54321', 'status' => 'available'])->save();
        $driver = new LimoDriver();
        $driver->forceFill(['name' => 'Rashid', 'active' => true])->save();

        $leg->forceFill(['car_id' => $car->id, 'driver_id' => $driver->id])->saveQuietly();
    }

    public function test_a_locked_user_gets_only_the_short_version_of_an_unassigned_trip(): void
    {
        $this->operations();
        $leg = $this->trip();
        $rows = app(LimoQueueRows::class);

        $row = $rows->row($leg);
        $this->assertSame('🔒 Bahrain', $row['pickup']);
        $this->assertSame('🔒 Saudi Arabia', $row['dropoff']);
        $this->assertSame('', $row['contact_phone']);
        $this->assertSame('', $row['passenger']);
        $this->assertSame('', $row['comments']);
        // Still enough to plan the trip: number, time, customer.
        $this->assertSame('20777', $row['reference']);
        $this->assertSame('Ministry Travel Desk', $row['customer']);

        // Nothing to copy out and send to a driver.
        $this->assertSame('', $rows->whatsappText($leg));
        $this->assertNotContains(self::ADDRESS, $rows->confirmationLines($leg));
    }

    public function test_assigning_the_car_and_driver_shows_everything(): void
    {
        $this->operations();
        $leg = $this->trip();
        $this->crew($leg);
        $leg = $leg->fresh() ?? $leg;

        $rows = app(LimoQueueRows::class);
        $this->assertSame(self::ADDRESS, $rows->row($leg)['pickup']);
        $this->assertStringContainsString(self::ADDRESS, $rows->whatsappText($leg));
    }

    public function test_people_who_are_not_ticked_see_everything(): void
    {
        $this->operations(locked: false);
        $leg = $this->trip();

        $this->assertSame(self::ADDRESS, app(LimoQueueRows::class)->row($leg)['pickup']);
    }

    public function test_the_owner_is_never_locked(): void
    {
        $this->operations(locked: true, superAdmin: true);
        $leg = $this->trip();

        $this->assertSame(self::ADDRESS, app(LimoQueueRows::class)->row($leg)['pickup']);
    }

    public function test_the_bookings_list_offers_no_copy_and_no_address(): void
    {
        $this->operations();
        $this->trip();

        Livewire::test(Bookings::class)
            ->call('openTab', 'all')
            ->assertSeeHtml('data-copy-locked')
            ->assertSee('🔒 Bahrain')
            ->assertDontSee(self::ADDRESS)
            ->assertDontSee(self::PAX_PHONE);
    }

    public function test_the_preview_and_quick_edit_stay_shut(): void
    {
        $this->operations();
        $leg = $this->trip();

        Livewire::test(Bookings::class)
            ->call('openPreview', $leg->legable_id)
            ->assertSet('previewingId', null)
            ->call('openEdit', $leg->legable_id, $leg->id)
            ->assertSet('editingId', null)
            ->assertSet('edit', []);
    }

    public function test_the_booking_page_loads_nothing_but_the_short_version(): void
    {
        $this->operations();
        $leg = $this->trip();

        Livewire::test(BookingForm::class, ['id' => $leg->legable_id])
            ->assertSet('crewLocked', true)
            // Not loaded at all: a public property ships inside the page.
            ->assertSet('pax_contact', '')
            ->assertSet('notes', '')
            ->assertSet('legs', [])
            ->assertSeeHtml('data-booking-locked')
            ->assertSee('20777')
            ->assertDontSee(self::ADDRESS);
    }

    public function test_nothing_on_a_held_back_booking_page_can_be_acted_on(): void
    {
        $this->operations();
        $leg = $this->trip();

        Livewire::test(BookingForm::class, ['id' => $leg->legable_id])
            ->call('save')
            ->assertForbidden();

        // And the booking was not overwritten with the empty page.
        $this->assertSame(self::PAX_PHONE, LimoBooking::query()->find($leg->legable_id)?->pax_contact);
    }

    public function test_the_booking_page_is_whole_once_every_trip_is_crewed(): void
    {
        $this->operations();
        $leg = $this->trip(crewed: true);

        Livewire::test(BookingForm::class, ['id' => $leg->legable_id])
            ->assertSet('crewLocked', false)
            ->assertSet('pax_contact', self::PAX_PHONE);
    }

    public function test_the_service_order_waits_for_the_crew(): void
    {
        $this->operations();
        $leg = $this->trip();

        try {
            (new ServiceOrderController())($leg->id, app(ServiceOrderPdf::class));
            $this->fail('A held-back trip\'s service order must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_the_whatsapp_assistant_keeps_the_places_back_too(): void
    {
        $locked = $this->operations();
        $this->trip();
        $actions = app(AssistantActions::class);

        $hidden = $actions->findBooking('BK/00777', $locked);
        $this->assertTrue($hidden['details_hidden'] ?? false);
        $this->assertArrayNotHasKey('from', $hidden);

        $open = $actions->findBooking('BK/00777', User::factory()->create(['is_admin' => true]));
        $this->assertSame(self::ADDRESS, $open['from'] ?? null);
    }

    public function test_the_migration_locks_abbas_and_ali_mak_and_nobody_else(): void
    {
        $abbas = User::factory()->create(['name' => 'Abbas Hamdan', 'email' => 'hamdanabbas98@gmail.com']);
        $ali = User::factory()->create(['name' => 'Ali Makki', 'email' => 'ali.makki@example.test']);
        $otherAli = User::factory()->create(['name' => 'Ali Hassan', 'email' => 'ali.hassan@example.test']);

        $migration = include base_path('database/migrations/2026_10_31_100001_add_trip_details_locked_to_users.php');
        $migration->up();

        $this->assertTrue((bool) $abbas->fresh()?->trip_details_locked);
        $this->assertTrue((bool) $ali->fresh()?->trip_details_locked);
        $this->assertFalse((bool) $otherAli->fresh()?->trip_details_locked);
    }

    public function test_only_the_owner_can_change_who_is_ticked(): void
    {
        $staff = User::factory()->create(['name' => 'Yousif']);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(LimoCrewAlertSettings::class)
            ->set('lockedUserIds', [$staff->id])
            ->call('saveLockedStaff')
            ->assertForbidden();
        $this->assertFalse((bool) $staff->fresh()?->trip_details_locked);

        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($owner);
        Livewire::test(LimoCrewAlertSettings::class)
            ->set('lockedUserIds', [$staff->id, $owner->id])
            ->call('saveLockedStaff')
            ->assertSet('staffSaved', true);

        $this->assertTrue((bool) $staff->fresh()?->trip_details_locked);
        // An owner can't be ticked, even by sending their id.
        $this->assertFalse((bool) $owner->fresh()?->trip_details_locked);
    }
}

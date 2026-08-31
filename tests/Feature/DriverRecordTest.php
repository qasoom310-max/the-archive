<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\Driver;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\DriverJobHistory;
use Tests\TestCase;

/**
 * The driver record: one person, one record, whichever app you came from —
 * with the papers that decide whether they can go out, and everything they have
 * been given to do.
 */
final class DriverRecordTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('rental');
    }

    /* ── One record, not two ─────────────────────────────────────────────── */

    /**
     * The same people drive for both apps. Two tables would drift apart the
     * first time somebody renewed a licence in only one of them.
     */
    public function test_both_apps_read_and_write_one_driver_store(): void
    {
        $this->assertSame(
            (new Driver())->getTable(),
            (new LimoDriver())->getTable(),
            'Drivers must be one store shared by both transport apps.',
        );

        // Added in Rent A Car…
        $created = Driver::query()->create(['name' => 'Rashid', 'phone' => '39000000']);

        // …and immediately dispatchable in Limousine, as the same row.
        $seen = LimoDriver::query()->find($created->id);
        $this->assertSame('Rashid', $seen?->name);

        // A correction in one app is a correction everywhere.
        $seen?->update(['phone' => '39111111']);
        $this->assertSame('39111111', $created->fresh()?->phone);
        $this->assertSame(1, Driver::query()->count());
    }

    /* ── Papers ──────────────────────────────────────────────────────────── */

    public function test_a_driver_carries_their_cpr_and_licence(): void
    {
        $driver = Driver::query()->create([
            'name' => 'Rashid',
            'cpr' => '921000448',
            'cpr_doc' => 'drivers/cpr.pdf',
            'license_no' => 'DL-4471',
            'license_expiry' => now()->addYear()->toDateString(),
            'license_doc' => 'drivers/licence.pdf',
        ]);

        $fresh = $driver->fresh();
        $this->assertSame('drivers/cpr.pdf', $fresh?->cpr_doc);
        $this->assertSame('drivers/licence.pdf', $fresh?->license_doc);
        $this->assertFalse($fresh?->licenceExpired());
        $this->assertTrue($fresh?->canBeDispatched());
    }

    public function test_an_expired_licence_stops_a_driver_going_out(): void
    {
        $driver = Driver::query()->create([
            'name' => 'Rashid',
            'license_expiry' => now()->subDay()->toDateString(),
        ]);

        $this->assertTrue($driver->licenceExpired());
        $this->assertFalse($driver->canBeDispatched());
        $this->assertStringContainsString('licence expired', strtolower((string) $driver->dispatchBlockReason()));
    }

    /** A licence with no date on file is unknown, not expired. */
    public function test_no_expiry_on_file_does_not_block_anyone(): void
    {
        $driver = Driver::query()->create(['name' => 'Rashid']);

        $this->assertFalse($driver->licenceExpired());
        $this->assertTrue($driver->canBeDispatched());
    }

    public function test_a_licence_about_to_run_out_is_flagged_but_still_allowed(): void
    {
        $driver = Driver::query()->create([
            'name' => 'Rashid',
            'license_expiry' => now()->addDays(10)->toDateString(),
        ]);

        $this->assertTrue($driver->licenceExpiringSoon());
        $this->assertTrue($driver->canBeDispatched());
    }

    /** Both models answer the licence question identically — one rule, one place. */
    public function test_both_models_agree_on_the_licence(): void
    {
        $created = Driver::query()->create([
            'name' => 'Rashid',
            'license_expiry' => now()->subDay()->toDateString(),
        ]);

        $this->assertTrue(LimoDriver::query()->find($created->id)?->licenceExpired());
        $this->assertFalse(LimoDriver::query()->find($created->id)?->canBeDispatched());
    }

    /* ── The rule with teeth ─────────────────────────────────────────────── */

    private function confirmedLeg(): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00001',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_CONFIRMED,
            'prepared_by' => 'Qassim',
        ]);

        return LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '10001',
            'status' => LimoLeg::STATUS_CONFIRMED,
            'start_at' => now()->addDay(),
            'from_location' => 'Hotel',
            'to_location' => 'Airport',
            'car_id' => $this->car()->id,
            'rate' => 45, 'net_amount' => 45,
        ]);
    }

    private function car(): Vehicle
    {
        return Vehicle::query()->create([
            'name' => 'Mercedes E-Class',
            'plate_number' => '123456',
            'status' => 'available',
        ]);
    }

    /** A hidden option is a courtesy; the save is the rule. */
    public function test_an_expired_driver_cannot_be_assigned_to_a_trip(): void
    {
        $leg = $this->confirmedLeg();
        $expired = Driver::query()->create([
            'name' => 'Rashid',
            'license_expiry' => now()->subDay()->toDateString(),
        ]);

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->set('assignCar', (string) $leg->car_id)
            ->set('assignDriver', (string) $expired->id)
            ->call('saveAssign')
            ->assertHasErrors('assignDriver');

        $this->assertNull($leg->fresh()?->driver_id);
        $this->assertSame(LimoLeg::STATUS_CONFIRMED, $leg->fresh()?->status);
    }

    public function test_an_expired_driver_is_not_offered_in_the_picker(): void
    {
        $leg = $this->confirmedLeg();
        Driver::query()->create(['name' => 'Expired One', 'license_expiry' => now()->subDay()->toDateString()]);
        Driver::query()->create(['name' => 'Valid One', 'license_expiry' => now()->addYear()->toDateString()]);

        $component = Livewire::test(Bookings::class)->call('openAssign', $leg->id);

        $labels = array_column($component->viewData('driverOptions'), 'label');
        $this->assertStringContainsString('Valid One', implode(' ', $labels));
        $this->assertStringNotContainsString('Expired One', implode(' ', $labels));
    }

    /* ── What they have been doing ───────────────────────────────────────── */

    /**
     * The point of the panel: one list across BOTH businesses. The office asks
     * what this person has been doing, not what they did in one app.
     */
    public function test_the_history_spans_limousine_and_rental(): void
    {
        $driver = Driver::query()->create(['name' => 'Rashid']);
        $car = $this->car();

        $leg = $this->confirmedLeg();
        $leg->forceFill([
            'driver_id' => $driver->id,
            'driver' => 'Rashid',
            'vehicle' => 'Mercedes E-Class',
            'start_at' => now()->subDays(2),
        ])->save();

        $order = new RentalOrder();
        $order->forceFill([
            'reference' => 'RO/00019',
            'driver_id' => $driver->id,
            'vehicle_id' => $car->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'created_by_user_id' => \Illuminate\Support\Facades\Auth::id(),
            'state' => RentalOrder::STATE_DRAFT,
        ])->save();

        $jobs = app(DriverJobHistory::class)->for((int) $driver->id);

        $this->assertCount(2, $jobs);

        // Newest first, so the rental (yesterday) leads the trip (two days ago).
        $this->assertSame('rental', $jobs[0]['kind']);
        $this->assertSame('RO/00019', $jobs[0]['reference']);
        $this->assertSame('Qassim', $jobs[0]['given_by']);

        $this->assertSame('limousine', $jobs[1]['kind']);
        $this->assertSame('10001', $jobs[1]['reference']);
        // Who handed the trip out, and what they drove.
        $this->assertSame('Qassim', $jobs[1]['given_by']);
        $this->assertSame('Mercedes E-Class', $jobs[1]['car']);
    }

    public function test_the_history_only_shows_that_drivers_jobs(): void
    {
        $mine = Driver::query()->create(['name' => 'Rashid']);
        $theirs = Driver::query()->create(['name' => 'Someone Else']);

        $leg = $this->confirmedLeg();
        $leg->forceFill(['driver_id' => $theirs->id])->save();

        $this->assertSame([], app(DriverJobHistory::class)->for((int) $mine->id));
    }

    public function test_a_driver_with_no_jobs_gets_an_empty_list(): void
    {
        $driver = Driver::query()->create(['name' => 'Rashid']);

        $this->assertSame([], app(DriverJobHistory::class)->for((int) $driver->id));
    }
}

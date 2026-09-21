<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Modules\Limousine\Console\FindDuplicateTrips;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * A read-only report the owner can trust before anything about a suspected
 * duplicate-import problem is acted on: it never writes anything, so running
 * it twice, or against the wrong workspace, costs nothing.
 */
final class LimoFindDuplicateTripsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        $this->registerCommand();
    }

    /**
     * A module command registers on the boot AFTER its module is installed,
     * and the module is installed in setUp — so the test adds it by hand,
     * the same way {@see LimoMatchDriverNamesTest} does.
     */
    private function registerCommand(): void
    {
        $kernel = $this->app?->make(Kernel::class);
        \assert($kernel instanceof ConsoleKernel);
        $kernel->registerCommand(new FindDuplicateTrips());
    }

    /**
     * @return array{booking: LimoBooking, leg: LimoLeg}
     */
    private function trip(
        string $customerName,
        Carbon $pickupAt,
        float $fare,
        ?string $notes = null,
        ?string $paxName = null,
    ): array {
        $customer = LimoCustomer::query()->firstOrCreate(['name' => $customerName]);

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => $pickupAt,
            'fare' => $fare,
            'amount' => $fare,
            'status' => LimoBooking::STATUS_COMPLETED,
            'payment_status' => LimoBooking::PAYMENT_PAID,
            'notes' => $notes,
            'pax_name' => $paxName,
        ]);

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'start_at' => $pickupAt,
            'from_location' => 'Bahrain Airport',
            'to_location' => 'Seef',
            'rate' => $fare,
            'net_amount' => $fare,
        ]);

        return ['booking' => $booking, 'leg' => $leg];
    }

    public function test_a_clean_database_reports_nothing_to_look_at(): void
    {
        $this->trip('Ahmed', Carbon::parse('2026-06-01 10:00'), 45.0);
        $this->trip('Ahmed', Carbon::parse('2026-06-05 10:00'), 45.0);

        $this->artisan('limo:find-duplicate-trips')
            ->expectsOutputToContain('No exact duplicate bookings')
            ->expectsOutputToContain('No near-duplicate bookings')
            ->expectsOutputToContain('No booking has two identical legs')
            ->assertSuccessful();
    }

    public function test_an_exact_duplicate_booking_is_reported(): void
    {
        $this->trip('Sara', Carbon::parse('2026-06-01 10:00:00'), 45.0);
        $this->trip('Sara', Carbon::parse('2026-06-01 10:00:00'), 45.0);

        $this->artisan('limo:find-duplicate-trips')
            ->expectsOutputToContain('1 group(s) of EXACT duplicate bookings')
            ->assertSuccessful();
    }

    public function test_a_near_duplicate_within_the_window_is_reported(): void
    {
        $this->trip('Mona', Carbon::parse('2026-06-01 10:00:00'), 45.0);
        $this->trip('Mona', Carbon::parse('2026-06-01 10:01:30'), 45.0);

        $this->artisan('limo:find-duplicate-trips')
            ->expectsOutputToContain('1 group(s) of NEAR-duplicate bookings')
            ->assertSuccessful();
    }

    public function test_a_pickup_outside_the_window_is_not_reported(): void
    {
        $this->trip('Layla', Carbon::parse('2026-06-01 10:00:00'), 45.0);
        $this->trip('Layla', Carbon::parse('2026-06-01 10:05:00'), 45.0);

        $this->artisan('limo:find-duplicate-trips')
            ->expectsOutputToContain('No near-duplicate bookings')
            ->assertSuccessful();
    }

    /**
     * A trip re-entered in the ERP that deliberately points back at its old
     * booking number is a known cross-reference, not an accidental double
     * import — the same exemption the importer's own TWIN check makes.
     */
    public function test_a_deliberate_cross_reference_note_is_not_reported(): void
    {
        $this->trip('Khalid', Carbon::parse('2026-06-01 10:00:00'), 45.0);
        $this->trip('Khalid', Carbon::parse('2026-06-01 10:01:00'), 45.0, notes: 'Booking #9001');

        $this->artisan('limo:find-duplicate-trips')
            ->expectsOutputToContain('No near-duplicate bookings')
            ->assertSuccessful();
    }

    /**
     * A wedding or company outing books many real cars under one customer
     * account, all at the same scheduled time and the same per-car fare —
     * exactly what the customer+time+fare fingerprint matches on. A
     * different passenger on each row proves these are separate trips, not
     * one entered twice, so it must not inflate the "accidental extras" total.
     */
    public function test_bookings_with_different_passengers_are_not_counted_as_accidental_duplicates(): void
    {
        $this->trip('Jain Wedding', Carbon::parse('2026-06-01 10:00:00'), 32.0, paxName: 'Priyanka Guru Prasad');
        $this->trip('Jain Wedding', Carbon::parse('2026-06-01 10:00:00'), 32.0, paxName: 'Prathviraj Shastry');

        $this->artisan('limo:find-duplicate-trips')
            ->expectsOutputToContain('look like legitimate multi-vehicle bookings')
            ->expectsOutputToContain('Probably NOT duplicates')
            ->expectsOutputToContain('Total: 0 row(s) look like accidental extras')
            ->assertSuccessful();
    }

    /**
     * Same shape, but the tell is the driver instead of the passenger name —
     * two different cars sent out for the same job, not the same car logged
     * twice.
     */
    public function test_bookings_with_different_drivers_are_not_counted_as_accidental_duplicates(): void
    {
        $this->trip('Zuber Issa', Carbon::parse('2026-06-01 10:00:00'), 70.0, notes: 'Booking #6253 | Status: Closed | Driver: adnan');
        $this->trip('Zuber Issa', Carbon::parse('2026-06-01 10:00:00'), 70.0, notes: 'Booking #6254 | Status: Closed | Driver: syatin');

        $this->artisan('limo:find-duplicate-trips')
            ->expectsOutputToContain('look like legitimate multi-vehicle bookings')
            ->expectsOutputToContain('Total: 0 row(s) look like accidental extras')
            ->assertSuccessful();
    }

    /** The same driver on every row (or none at all) is not a distinguishing tell, so it still counts as accidental. */
    public function test_bookings_with_the_same_driver_are_still_counted_as_accidental_duplicates(): void
    {
        $this->trip('Layla', Carbon::parse('2026-06-01 10:00:00'), 45.0, notes: 'Booking #100 | Status: Closed | Driver: admin');
        $this->trip('Layla', Carbon::parse('2026-06-01 10:00:00'), 45.0, notes: 'Booking #101 | Status: Closed | Driver: admin');

        $this->artisan('limo:find-duplicate-trips')
            ->expectsOutputToContain('Accidental-looking')
            ->expectsOutputToContain('Total: 1 row(s) look like accidental extras')
            ->assertSuccessful();
    }

    public function test_two_identical_legs_on_the_same_booking_are_reported(): void
    {
        $trip = $this->trip('Fahad', Carbon::parse('2026-06-01 10:00:00'), 45.0);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $trip['booking']->id,
            'sequence' => 2,
            'start_at' => Carbon::parse('2026-06-01 10:00:00'),
            'from_location' => 'Bahrain Airport',
            'to_location' => 'Seef',
            'rate' => 45.0,
            'net_amount' => 45.0,
        ]);

        $this->artisan('limo:find-duplicate-trips')
            ->expectsOutputToContain('1 booking(s) with a duplicate leg on the same booking')
            ->assertSuccessful();
    }

    public function test_it_writes_nothing_at_all(): void
    {
        $this->trip('Yara', Carbon::parse('2026-06-01 10:00:00'), 45.0);
        $this->trip('Yara', Carbon::parse('2026-06-01 10:00:00'), 45.0);

        $bookingsBefore = LimoBooking::query()->count();
        $legsBefore = LimoLeg::query()->count();

        $this->artisan('limo:find-duplicate-trips')->assertSuccessful();

        $this->assertSame($bookingsBefore, LimoBooking::query()->count());
        $this->assertSame($legsBefore, LimoLeg::query()->count());
    }
}

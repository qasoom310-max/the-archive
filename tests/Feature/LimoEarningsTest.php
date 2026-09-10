<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Limousine\Livewire\LimoEarnings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\LimoPerformance;
use Tests\TestCase;

/**
 * The limousine desk reported on the three units it actually turns on.
 *
 * There is deliberately no per-car table: no trip ever recorded a car, so one
 * would be a single row reading "not recorded" - the mistake the first revenue
 * breakdown made. What is pinned hardest here is that a section which cannot
 * be built SAYS SO, rather than rendering an empty table that looks broken.
 */
final class LimoEarningsTest extends TestCase
{
    use DatabaseMigrations;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = CarbonImmutable::create(2026, 6, 30, 12, 0, 0);
        CarbonImmutable::setTestNow($this->today);

        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');

        // A module's routes register at boot, which has already happened by the
        // time an in-test install runs.
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
    }

    /**
     * @param  array<string, mixed>  $leg
     */
    private function trip(string $at, float $fare, bool $paid = true, array $leg = [], string $status = 'completed'): LimoBooking
    {
        $customer = LimoCustomer::query()->firstOrCreate(['name' => 'Dadabhai'], ['type' => 'company']);

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => $at,
            'fare' => $fare,
            'amount' => $fare,
            'status' => $status,
            'payment_status' => $paid ? LimoBooking::PAYMENT_PAID : LimoBooking::PAYMENT_UNPAID,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'service_type' => 'transfer',
            'start_at' => $at,
            'net_amount' => $fare,
            ...$leg,
        ]);

        return $booking;
    }

    // ── Sections that cannot be built must say so ────────────────────────────

    public function test_a_section_with_no_data_behind_it_says_so(): void
    {
        // The whole reason this page exists: the first breakdown rendered a
        // row reading "No car type recorded, 100%" instead of admitting the
        // records could not answer the question.
        $this->trip('2026-03-01 10:00:00', 100);

        $report = (new LimoPerformance(2026))->report();

        $this->assertFalse($report['drivers']['available']);
        $this->assertFalse($report['routes']['available']);
        // But the trip itself is still counted everywhere it can be.
        $this->assertSame(1, $report['summary']['trips']);
        $this->assertEqualsWithDelta(100.0, $report['summary']['earned'], 0.001);
    }

    public function test_the_page_names_what_the_records_cannot_answer(): void
    {
        $this->trip('2026-03-01 10:00:00', 100);
        $this->actingAs($this->owner());

        Livewire::test(LimoEarnings::class)
            ->assertSee('names a driver')
            ->assertSee('records where it went');
    }

    // ── Drivers ──────────────────────────────────────────────────────────────

    public function test_drivers_are_ranked_by_what_they_collected(): void
    {
        $this->trip('2026-03-01 10:00:00', 300, true, ['driver' => 'Ramesh']);
        $this->trip('2026-03-02 10:00:00', 100, true, ['driver' => 'John']);
        $this->trip('2026-03-03 10:00:00', 200, true, ['driver' => 'Ramesh']);

        $drivers = (new LimoPerformance(2026))->report()['drivers'];

        $this->assertTrue($drivers['available']);
        $this->assertSame('Ramesh', $drivers['rows'][0]['name']);
        $this->assertEqualsWithDelta(500.0, $drivers['rows'][0]['earned'], 0.001);
        $this->assertSame(2, $drivers['rows'][0]['trips']);
        $this->assertEqualsWithDelta(250.0, $drivers['rows'][0]['avgFare'], 0.001);
    }

    public function test_the_same_driver_written_differently_is_one_person(): void
    {
        // A text field filled in by hand over years will not be consistent.
        $this->trip('2026-03-01 10:00:00', 100, true, ['driver' => 'RAMESH']);
        $this->trip('2026-03-02 10:00:00', 100, true, ['driver' => '  ramesh ']);
        $this->trip('2026-03-03 10:00:00', 100, true, ['driver' => 'Ramesh']);

        $drivers = (new LimoPerformance(2026))->report()['drivers'];

        $this->assertCount(1, $drivers['rows']);
        $this->assertSame(3, $drivers['rows'][0]['trips']);
    }

    public function test_busy_at_a_low_fare_and_quiet_at_a_high_one_read_differently(): void
    {
        // Both problems hide inside a low total and need opposite fixes.
        for ($i = 1; $i <= 10; $i++) {
            $this->trip("2026-03-{$i} 10:00:00", 20, true, ['driver' => 'Cheap']);
        }
        $this->trip('2026-04-01 10:00:00', 400, true, ['driver' => 'Premium']);

        $rows = collect((new LimoPerformance(2026))->report()['drivers']['rows'])->keyBy('name');

        $this->assertSame('cheap', $rows['Cheap']['verdict']);
        $this->assertSame('premium', $rows['Premium']['verdict']);
    }

    public function test_work_run_but_not_paid_is_shown_apart_from_what_was_collected(): void
    {
        $this->trip('2026-03-01 10:00:00', 200, true, ['driver' => 'Ramesh']);
        $this->trip('2026-03-02 10:00:00', 150, false, ['driver' => 'Ramesh']);

        $report = (new LimoPerformance(2026))->report();
        $row = $report['drivers']['rows'][0];

        $this->assertEqualsWithDelta(200.0, $row['earned'], 0.001);
        $this->assertEqualsWithDelta(150.0, $row['unpaid'], 0.001);
        // The trip still happened, so it counts as work done.
        $this->assertSame(2, $row['trips']);
        $this->assertEqualsWithDelta(150.0, $report['summary']['unpaid'], 0.001);
    }

    public function test_trips_naming_no_driver_are_counted_but_reported_separately(): void
    {
        $this->trip('2026-03-01 10:00:00', 100, true, ['driver' => 'Ramesh']);
        $this->trip('2026-03-02 10:00:00', 500, true);

        $report = (new LimoPerformance(2026))->report();

        $this->assertSame(1, $report['drivers']['unnamed']);
        $this->assertCount(1, $report['drivers']['rows']);
        // Not in the driver table, but never lost from the takings.
        $this->assertEqualsWithDelta(600.0, $report['summary']['earned'], 0.001);
    }

    // ── Routes ───────────────────────────────────────────────────────────────

    public function test_routes_are_compared_against_the_same_route_a_year_before(): void
    {
        // Not against the fleet average: a genuinely cheap route is not a
        // decline, and only its own history says whether it is slipping.
        $this->trip('2025-03-01 10:00:00', 100, true, ['from_location' => 'Airport', 'to_location' => 'Juffair']);
        $this->trip('2026-03-01 10:00:00', 80, true, ['from_location' => 'Airport', 'to_location' => 'Juffair']);

        $routes = (new LimoPerformance(2026))->report()['routes'];

        $this->assertSame('Airport → Juffair', $routes['rows'][0]['label']);
        $this->assertEqualsWithDelta(80.0, $routes['rows'][0]['avgFare'], 0.001);
        $this->assertSame(-20, $routes['rows'][0]['shift']);
    }

    public function test_a_route_written_in_different_cases_is_one_route(): void
    {
        $this->trip('2026-03-01 10:00:00', 100, true, ['from_location' => 'AIRPORT', 'to_location' => 'juffair']);
        $this->trip('2026-03-02 10:00:00', 100, true, ['from_location' => 'Airport ', 'to_location' => ' Juffair']);

        $routes = (new LimoPerformance(2026))->report()['routes'];

        $this->assertCount(1, $routes['rows']);
        $this->assertSame(2, $routes['rows'][0]['trips']);
    }

    // ── The average fare, which a trip count hides ───────────────────────────

    public function test_the_headline_catches_a_desk_discounting_itself(): void
    {
        // More trips than last year, less money per trip. A trip count alone
        // would call this a good year.
        $this->trip('2025-03-01 10:00:00', 100);
        $this->trip('2026-03-01 10:00:00', 80);
        $this->trip('2026-03-02 10:00:00', 80);

        $summary = (new LimoPerformance(2026))->report()['summary'];

        $this->assertSame(2, $summary['trips']);
        $this->assertEqualsWithDelta(80.0, $summary['avgFare'], 0.001);
        $this->assertEqualsWithDelta(100.0, $summary['lastAvgFare'], 0.001);
        $this->assertSame(-20, $summary['fareShift']);
    }

    // ── Demand ───────────────────────────────────────────────────────────────

    public function test_the_grid_buckets_trips_by_day_and_hour_band(): void
    {
        // 2026-06-04 is a Thursday.
        $this->trip('2026-06-04 18:00:00', 50);
        $this->trip('2026-06-04 19:30:00', 50);
        $this->trip('2026-06-04 11:00:00', 50);

        $grid = (new LimoPerformance(2026))->report()['demand']['grid'];

        $this->assertSame(2, $grid['evening'][4]['trips']);
        $this->assertSame(1, $grid['day'][4]['trips']);
        $this->assertSame(0, $grid['night'][4]['trips']);
    }

    public function test_the_night_band_wraps_past_midnight(): void
    {
        // The one band that cannot be tested with a plain between.
        $this->trip('2026-06-04 23:30:00', 50);
        $this->trip('2026-06-05 02:00:00', 50);

        $grid = (new LimoPerformance(2026))->report()['demand']['grid'];

        $this->assertSame(1, $grid['night'][4]['trips']);
        $this->assertSame(1, $grid['night'][5]['trips']);
    }

    public function test_one_lucky_trip_is_not_called_the_best_paying_slot(): void
    {
        // Otherwise a single airport run at 400 would send the whole roster to
        // an hour that never repeats.
        $this->trip('2026-06-04 23:30:00', 400);

        for ($d = 1; $d <= 6; $d++) {
            $this->trip("2026-06-0{$d} 18:00:00", 60);
        }

        $demand = (new LimoPerformance(2026))->report()['demand'];

        $this->assertNotSame('night|4', $demand['bestPaying']);
    }

    public function test_a_cancelled_trip_is_not_work_and_not_money(): void
    {
        $this->trip('2026-03-01 10:00:00', 100, true, ['driver' => 'Ramesh']);
        $this->trip('2026-03-02 10:00:00', 900, true, ['driver' => 'Ramesh'], 'cancelled');

        $report = (new LimoPerformance(2026))->report();

        $this->assertSame(1, $report['summary']['trips']);
        $this->assertEqualsWithDelta(100.0, $report['summary']['earned'], 0.001);
    }

    public function test_a_quotations_legs_are_never_counted_as_takings(): void
    {
        // Legs are shared with quotations, so without the type filter a quote
        // nobody accepted would be reported as money earned.
        $this->trip('2026-03-01 10:00:00', 100);

        LimoLeg::query()->create([
            'legable_type' => 'Modules\Limousine\Models\LimoQuotation',
            'legable_id' => 999,
            'sequence' => 0,
            'service_type' => 'transfer',
            'start_at' => '2026-03-05 10:00:00',
            'net_amount' => 5000,
        ]);

        $this->assertSame(1, (new LimoPerformance(2026))->report()['summary']['trips']);
    }

    // ── The page ─────────────────────────────────────────────────────────────

    public function test_the_page_is_owner_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $this->get('/app/limousine/earnings')->assertForbidden();

        $this->actingAs($this->owner());
        $this->get('/app/limousine/earnings')->assertOk();
    }

    public function test_the_owner_sees_drivers_routes_and_the_demand_grid(): void
    {
        $this->trip('2026-06-04 18:00:00', 120, true, [
            'driver' => 'Ramesh', 'from_location' => 'Airport', 'to_location' => 'Juffair',
        ]);
        $this->actingAs($this->owner());

        Livewire::test(LimoEarnings::class)
            ->assertSee('Ramesh')
            ->assertSee('Airport → Juffair')
            ->assertSee('When the work lands')
            ->assertSee('Busiest slot');
    }

    public function test_switching_year_is_owner_gated_and_bounded(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(LimoEarnings::class)
            ->call('setYear', 2025)
            ->assertSet('year', 2025)
            // A wild value would build twelve empty months for nothing.
            ->call('setYear', 1200)
            ->assertSet('year', 2025);
    }
}

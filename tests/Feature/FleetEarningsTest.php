<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Targets\RevenueSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Livewire\FleetEarnings;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Support\FleetPerformance;
use Tests\TestCase;

/**
 * Whether each car is worth owning.
 *
 * The property that matters most is that revenue alone ranks a fleet
 * BACKWARDS: a car earning more over far more rented days is the worse asset,
 * and the old month-by-month matrix put it first. Everything else here exists
 * to make that judgement trustworthy.
 */
final class FleetEarningsTest extends TestCase
{
    use DatabaseMigrations;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        // Exactly half the year gone: 181 of 365 days, so pace and utilisation
        // are checkable by hand rather than by re-implementing the maths.
        $this->today = CarbonImmutable::create(2026, 6, 30, 12, 0, 0);
        CarbonImmutable::setTestNow($this->today);

        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');

        // A module's routes register at boot, which has already happened by the
        // time an in-test install runs - so the page's own URLs have to be
        // loaded by hand, as the other module route tests do.
        Route::middleware('web')->group(base_path('Modules/Rental/routes/web.php'));
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

    private function car(string $name, string $plate, float $monthly = 0.0, float $yearly = 0.0, bool $active = true): Vehicle
    {
        return Vehicle::query()->create([
            'name' => $name,
            'plate_no' => $plate,
            'monthly_target' => $monthly,
            'yearly_target' => $yearly,
            'daily_rate' => 20.0,
            'active' => $active,
            'is_outside' => false,
        ]);
    }

    private function hire(?Vehicle $car, string $from, string $to, float $total, float $outside = 0.0): void
    {
        $customer = RentalCustomer::query()->firstOrCreate(['name' => 'Qassim'], ['phone' => '39000001']);

        RentalOrder::query()->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $car?->id,
            'start_date' => $from,
            'end_date' => $to,
            'total' => $total,
            'outside_cost' => $outside,
            'state' => RentalOrder::STATE_CLOSED,
            'payment_status' => RentalOrder::PAYMENT_PAID,
        ]);
    }

    /** @return array<string, mixed> */
    private function rowFor(string $plate, int $year = 2026): array
    {
        foreach ((new FleetPerformance($year))->report()['rows'] as $row) {
            if ($row['plate'] === $plate) {
                return $row;
            }
        }

        $this->fail("No row for {$plate}");
    }

    // ── The point of the whole page ──────────────────────────────────────────

    public function test_the_car_that_billed_more_can_be_the_worse_asset(): void
    {
        // Busy earns 24,000 across 300 days. Choosy earns 17,000 across 120.
        // A revenue column ranks Busy first; per day owned, Choosy is better,
        // and that is the comparison the owner actually needs.
        $busy = $this->car('Busy', '111111');
        $choosy = $this->car('Choosy', '222222');

        $this->hire($busy, '2026-01-01', '2026-04-10', 24000);   // 100 days
        $this->hire($choosy, '2026-01-01', '2026-02-09', 17000); // 40 days

        $b = $this->rowFor('111111');
        $c = $this->rowFor('222222');

        $this->assertGreaterThan($c['total'], $b['total']);
        $this->assertGreaterThan($b['perRentedDay'], $c['perRentedDay']);
        $this->assertSame(100, $b['rentedDays']);
        $this->assertSame(40, $c['rentedDays']);
    }

    public function test_utilisation_is_measured_against_the_year_so_far(): void
    {
        // 181 days of 2026 have gone. A car hired for 100 of them is at 55%,
        // not 27% - measuring against all 365 would call every car a failure
        // until December.
        $car = $this->car('Sunny', '111111');
        $this->hire($car, '2026-01-01', '2026-04-10', 5000);

        $row = $this->rowFor('111111');

        $this->assertSame(181, $row['availableDays']);
        $this->assertSame(100, $row['rentedDays']);
        $this->assertSame(55, $row['utilisation']);
        $this->assertSame(81, $row['idleDays']);
    }

    public function test_a_hire_running_past_the_year_end_is_not_counted_twice(): void
    {
        $car = $this->car('Sunny', '111111');
        // Starts in the previous December and runs into January.
        $this->hire($car, '2025-12-20', '2026-01-10', 900);

        $row = $this->rowFor('111111');

        // Only the ten days that fall inside 2026.
        $this->assertSame(10, $row['rentedDays']);
    }

    public function test_an_open_hire_is_counted_up_to_today(): void
    {
        $car = $this->car('Sunny', '111111');
        RentalCustomer::query()->create(['name' => 'Open', 'phone' => '3900']);
        RentalOrder::query()->create([
            'vehicle_id' => $car->id,
            'start_date' => '2026-06-01',
            'end_date' => null,
            'total' => 500,
            'outside_cost' => 0,
            'state' => RentalOrder::STATE_ACTIVE,
            'payment_status' => RentalOrder::PAYMENT_UNPAID,
        ]);

        // 1 to 30 June inclusive.
        $this->assertSame(30, $this->rowFor('111111')['rentedDays']);
    }

    public function test_idle_cost_uses_the_cars_own_achieved_rate(): void
    {
        // 100 days at 50/day, then 81 days standing still. The standing days
        // are worth what this car actually gets, not a wish.
        $car = $this->car('Sunny', '111111');
        $this->hire($car, '2026-01-01', '2026-04-10', 5000);

        $row = $this->rowFor('111111');

        $this->assertEqualsWithDelta(50.0, $row['perRentedDay'], 0.001);
        $this->assertEqualsWithDelta(81 * 50.0, $row['idleCost'], 0.001);
    }

    public function test_a_car_that_never_moved_falls_back_to_its_list_rate(): void
    {
        // Otherwise a car that earned nothing would show no cost of standing
        // still, which is exactly backwards.
        $this->car('Never hired', '111111');

        $row = $this->rowFor('111111');

        $this->assertSame(0, $row['rentedDays']);
        $this->assertEqualsWithDelta(181 * 20.0, $row['idleCost'], 0.001);
    }

    // ── Money in and money out ───────────────────────────────────────────────

    public function test_revenue_is_net_of_outside_vendors_and_after_maintenance(): void
    {
        $car = $this->car('Sunny', '111111');
        $this->hire($car, '2026-03-01', '2026-03-10', 1000, 300);

        RentalMaintenance::query()->create([
            'vehicle_id' => $car->id,
            'date' => '2026-03-15',
            'type' => 'service',
            'cost' => 200,
            'status' => 'done',
        ]);

        $row = $this->rowFor('111111');

        $this->assertEqualsWithDelta(700.0, $row['earned'], 0.001);
        $this->assertEqualsWithDelta(200.0, $row['maintenance'], 0.001);
        $this->assertEqualsWithDelta(500.0, $row['net'], 0.001);
    }

    public function test_a_car_that_costs_more_than_it_earns_is_called_out(): void
    {
        $car = $this->car('Money pit', '111111', 500.0);
        $this->hire($car, '2026-03-01', '2026-03-02', 100);
        RentalMaintenance::query()->create([
            'vehicle_id' => $car->id, 'date' => '2026-03-15', 'type' => 'repair', 'cost' => 900, 'status' => 'done',
        ]);

        $this->assertSame('losing', $this->rowFor('111111')['verdict']);
    }

    public function test_not_hired_often_and_hired_too_cheaply_are_different_verdicts(): void
    {
        // They look identical in a revenue column and need opposite fixes:
        // one is a demand problem, the other a pricing one.
        $busy = $this->car('Busy but cheap', '111111', 1000.0);
        $this->hire($busy, '2026-01-01', '2026-06-29', 3000);      // ~100% used

        $idle = $this->car('Barely hired', '222222', 1000.0);
        $this->hire($idle, '2026-01-01', '2026-01-05', 3000);      // 5 days

        $this->assertSame('behind', $this->rowFor('111111')['verdict']);
        $this->assertSame('underused', $this->rowFor('222222')['verdict']);
    }

    // ── Targets ──────────────────────────────────────────────────────────────

    public function test_a_car_yearly_target_is_its_own_not_twelve_monthly_ones(): void
    {
        $this->car('Typed', '111111', 1000.0, 9000.0);
        $this->car('Derived', '222222', 1000.0, 0.0);

        $typed = $this->rowFor('111111');
        $derived = $this->rowFor('222222');

        $this->assertEqualsWithDelta(9000.0, $typed['yearlyTarget'], 0.001);
        $this->assertFalse($typed['yearlyDerived']);

        // Falls back to twelve monthly ones, and says on screen that it did.
        $this->assertEqualsWithDelta(12000.0, $derived['yearlyTarget'], 0.001);
        $this->assertTrue($derived['yearlyDerived']);
    }

    public function test_pace_is_measured_against_the_part_of_the_year_that_has_gone(): void
    {
        // Half the year gone, half the yearly target banked: exactly on pace,
        // even though only 50% of the year's target is in.
        $car = $this->car('Sunny', '111111', 0.0, 36500.0);
        $this->hire($car, '2026-03-01', '2026-03-02', 18100.0);

        $row = $this->rowFor('111111');

        $this->assertSame(50, $row['attainment']);
        $this->assertSame(100, $row['pace']);
        $this->assertSame('carrying', $row['verdict']);
    }

    // ── Rows that must not vanish ────────────────────────────────────────────

    public function test_money_billed_against_no_car_is_kept_as_others(): void
    {
        $this->car('Sunny', '111111');
        $this->hire(null, '2026-03-01', '2026-03-05', 400);

        $rows = (new FleetPerformance(2026))->report()['rows'];
        $others = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] === null));

        $this->assertCount(1, $others);
        $this->assertEqualsWithDelta(400.0, $others[0]['total'], 0.001);
        // No car, so no target and no utilisation to invent.
        $this->assertNull($others[0]['utilisation']);
    }

    public function test_a_retired_car_that_earned_is_shown_but_not_in_the_fleet_averages(): void
    {
        $live = $this->car('Live', '111111');
        $this->hire($live, '2026-01-01', '2026-01-10', 500);

        $gone = $this->car('Sold', '222222', 0.0, 0.0, false);
        $this->hire($gone, '2026-02-01', '2026-02-10', 300);

        $report = (new FleetPerformance(2026))->report();
        $retired = $this->rowFor('222222');

        $this->assertTrue($retired['retired']);
        $this->assertEqualsWithDelta(300.0, $retired['total'], 0.001);
        // We do not record WHEN it left, so counting it as available all year
        // would invent idle days it never had.
        $this->assertSame(0, $retired['availableDays']);
        $this->assertSame(181, $report['summary']['availableDays']);
    }

    public function test_a_retired_car_that_earned_nothing_is_left_out_entirely(): void
    {
        $this->car('Live', '111111');
        $this->car('Long gone', '999999', 0.0, 0.0, false);

        $plates = array_column((new FleetPerformance(2026))->report()['rows'], 'plate');

        $this->assertContains('111111', $plates);
        $this->assertNotContains('999999', $plates);
    }

    public function test_limousine_earnings_on_the_same_car_are_added_in(): void
    {
        // The limo desk books cars out of this fleet, so a car's whole
        // contribution was invisible while the two apps reported separately.
        $car = $this->car('Sunny', '111111');
        $this->hire($car, '2026-03-01', '2026-03-05', 500);

        $customer = LimoCustomer::query()->create(['name' => 'Dadabhai', 'type' => 'company']);
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => '2026-03-20 10:00:00',
            'fare' => 250,
            'amount' => 250,
            'status' => LimoBooking::STATUS_COMPLETED,
            'payment_status' => LimoBooking::PAYMENT_PAID,
        ]);
        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'car_id' => $car->id,
            'start_at' => '2026-03-20 10:00:00',
            'net_amount' => 250,
        ]);

        $report = (new FleetPerformance(2026))->report();
        $row = $this->rowFor('111111');

        $this->assertTrue($report['hasLimo']);
        $this->assertEqualsWithDelta(500.0, $row['earned'], 0.001);
        $this->assertEqualsWithDelta(250.0, $row['limo'], 0.001);
        $this->assertEqualsWithDelta(750.0, $row['total'], 0.001);
    }

    public function test_the_limousine_column_is_hidden_when_no_car_ever_earned_through_it(): void
    {
        // Imported legs carry no car, so this would be a column of zeroes.
        $this->car('Sunny', '111111');

        $this->assertFalse((new FleetPerformance(2026))->report()['hasLimo']);
    }

    // ── The page ─────────────────────────────────────────────────────────────

    public function test_the_page_is_owner_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $this->get('/app/rental/fleet')->assertForbidden();
        $this->get('/app/rental/fleet/export?format=csv')->assertForbidden();

        $this->actingAs($this->owner());
        $this->get('/app/rental/fleet')->assertOk();
    }

    public function test_the_owner_opens_a_car_scorecard(): void
    {
        $car = $this->car('Sunny', '111111', 1000.0);
        $this->hire($car, '2026-01-01', '2026-04-10', 5000);
        $this->actingAs($this->owner());

        Livewire::test(FleetEarnings::class)
            ->assertSee('Sunny')
            ->assertSee('Idle cost')
            ->call('toggleCar', $car->id)
            ->assertSet('openCar', $car->id)
            ->assertSee('How hard it worked')
            ->call('toggleCar', $car->id)
            ->assertSet('openCar', null);
    }

    public function test_a_non_owner_cannot_open_a_scorecard_by_calling_the_action(): void
    {
        // mount() alone is not a gate: Livewire dispatches straight to methods.
        $car = $this->car('Sunny', '111111');
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));

        Livewire::test(FleetEarnings::class)->assertForbidden();
    }

    public function test_the_export_carries_the_same_rows(): void
    {
        $car = $this->car('Sunny', '111111', 1000.0);
        $this->hire($car, '2026-03-01', '2026-03-10', 900);
        $this->actingAs($this->owner());

        $csv = $this->get('/app/rental/fleet/export?format=csv&year=2026');
        $csv->assertOk();

        $body = $csv->streamedContent();
        $this->assertStringContainsString('Sunny', $body);
        $this->assertStringContainsString('111111', $body);
    }

    // ── The limousine breakdown that had no data behind it ───────────────────

    public function test_the_limousine_breakdown_groups_by_the_kind_of_work(): void
    {
        // It used to group by `car_type`, which no import ever filled, so a
        // whole year landed in one row reading "No car type recorded, 100%".
        $customer = LimoCustomer::query()->create(['name' => 'Dadabhai', 'type' => 'company']);

        foreach ([['transfer', 100.0], ['chauffeur', 250.0], ['transfer', 50.0]] as [$kind, $fare]) {
            $booking = LimoBooking::query()->create([
                'customer_id' => $customer->id,
                'pickup_at' => '2026-06-05 10:00:00',
                'fare' => $fare,
                'amount' => $fare,
                'status' => LimoBooking::STATUS_COMPLETED,
                'payment_status' => LimoBooking::PAYMENT_PAID,
            ]);
            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => 1,
                'service_type' => $kind,
                'start_at' => '2026-06-05 10:00:00',
                'net_amount' => $fare,
            ]);
        }

        $schedule = app(RevenueSchedule::class)->forWindow(
            'limousine',
            $this->today->startOfMonth(),
            $this->today->endOfMonth(),
        );

        $this->assertSame('Chauffeur (hours)', $schedule['rows'][0]['label']);
        $this->assertEqualsWithDelta(250.0, $schedule['rows'][0]['amount'], 0.001);
        $this->assertSame('Pick & drop (transfer)', $schedule['rows'][1]['label']);
        $this->assertEqualsWithDelta(150.0, $schedule['rows'][1]['amount'], 0.001);
        $this->assertSame(2, $schedule['rows'][1]['jobs']);
        // Still adds up to the box above it.
        $this->assertEqualsWithDelta(400.0, $schedule['total'], 0.001);
    }

    // ── One month at a time ─────────────────────────────────────────────────

    public function test_a_month_reads_only_that_months_work(): void
    {
        // March: 10 days on hire, 500 earned. May: 5 days, 300.
        $car = $this->car('Sunny', '111111', 600.0);
        $this->hire($car, '2026-03-01', '2026-03-10', 500);
        $this->hire($car, '2026-05-01', '2026-05-05', 300);

        $row = collect((new FleetPerformance(2026, 3))->report()['rows'])->firstWhere('plate', '111111');

        $this->assertEqualsWithDelta(500.0, $row['total'], 0.001);
        $this->assertSame(10, $row['rentedDays']);
        // March has 31 days and is over by 30 June.
        $this->assertSame(31, $row['availableDays']);
        $this->assertSame(21, $row['idleDays']);
        // The matrix still shows every month.
        $this->assertEqualsWithDelta(300.0, $row['months'][5], 0.001);
    }

    public function test_a_month_is_judged_against_the_monthly_target(): void
    {
        // Never a year's target squeezed into one month: 500 against a 1,000
        // monthly target is 50%, whatever the yearly target says.
        $car = $this->car('Sunny', '111111', 1000.0, 36000.0);
        $this->hire($car, '2026-03-01', '2026-03-10', 500);

        $row = collect((new FleetPerformance(2026, 3))->report()['rows'])->firstWhere('plate', '111111');

        $this->assertEqualsWithDelta(1000.0, $row['target'], 0.001);
        $this->assertSame(50, $row['attainment']);
        // March is fully over, so pace equals attainment.
        $this->assertSame(50, $row['pace']);
    }

    public function test_the_current_month_is_paced_on_the_days_gone(): void
    {
        // 30 June: the month is all but done. 15 June: half. Test on the 15th.
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 12, 0, 0));
        $car = $this->car('Sunny', '111111', 3000.0);
        $this->hire($car, '2026-06-01', '2026-06-05', 1500);

        $row = collect((new FleetPerformance(2026, 6))->report()['rows'])->firstWhere('plate', '111111');

        // Half the monthly target banked with half the month gone: on pace.
        $this->assertSame(15, $row['availableDays']);
        $this->assertSame(100, $row['pace']);
    }

    public function test_a_month_still_to_come_has_nothing_to_judge(): void
    {
        $this->car('Sunny', '111111', 1000.0);

        $row = collect((new FleetPerformance(2026, 11))->report()['rows'])->firstWhere('plate', '111111');

        $this->assertSame(0, $row['availableDays']);
        $this->assertNull($row['pace']);
        $this->assertNull($row['utilisation']);
    }

    public function test_the_owner_switches_to_a_month_on_the_page(): void
    {
        $car = $this->car('Sunny', '111111', 600.0);
        $this->hire($car, '2026-03-01', '2026-03-10', 500);
        $this->actingAs($this->owner());

        Livewire::test(FleetEarnings::class)
            ->call('setMonth', 3)
            ->assertSet('month', 3)
            ->assertSee('Showing March 2026')
            ->call('setMonth', 13)
            ->assertSet('month', 3)
            ->call('setMonth', 0)
            ->assertSee('Showing 2026');
    }

    public function test_the_cost_card_shows_the_cost_not_what_is_left(): void
    {
        // It used to print the figure left AFTER costs under "What it cost".
        $car = $this->car('Sunny', '111111');
        $this->hire($car, '2026-03-01', '2026-03-10', 1000);
        RentalMaintenance::query()->create([
            'vehicle_id' => $car->id, 'date' => '2026-03-15', 'type' => 'service', 'cost' => 125, 'status' => 'done',
        ]);
        $this->actingAs($this->owner());

        Livewire::test(FleetEarnings::class)
            ->call('toggleCar', $car->id)
            ->assertSeeInOrder(['What it cost', '125.00', 'Left after costs', '875.00']);
    }

    public function test_the_download_follows_the_chosen_month(): void
    {
        $car = $this->car('Sunny', '111111');
        $this->hire($car, '2026-03-01', '2026-03-10', 500);
        $this->hire($car, '2026-05-01', '2026-05-05', 300);
        $this->actingAs($this->owner());

        $csv = $this->get('/app/rental/fleet/export?format=csv&year=2026&month=3');
        $csv->assertOk();
        $this->assertStringContainsString('fleet-earnings-2026-03', (string) $csv->headers->get('content-disposition'));
    }
}

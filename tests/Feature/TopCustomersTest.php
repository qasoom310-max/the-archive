<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Customers\TopCustomers;
use App\Erp\Modules\ModuleManager;
use App\Erp\Targets\RevenueSchedule;
use App\Erp\Targets\RevenueTargets;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Where the money came from, where the target came from, and which of the
 * customers who pay us have quietly stopped.
 *
 * The thing being pinned hardest is that a customer is judged against THEIR
 * OWN booking rhythm. One company-wide "quiet for 60 days" rule would call a
 * fortnightly corporate account and a once-a-year family the same thing, and
 * would therefore be wrong about one of them every time.
 */
final class TopCustomersTest extends TestCase
{
    use DatabaseMigrations;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = CarbonImmutable::create(2026, 9, 15, 11, 0, 0);
        CarbonImmutable::setTestNow($this->today);

        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
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

    private function customer(string $name): RentalCustomer
    {
        return RentalCustomer::query()->firstOrCreate(['name' => $name], ['phone' => '3900'.random_int(1000, 9999)]);
    }

    private function order(RentalCustomer $customer, string $date, float $total, ?Vehicle $vehicle = null): void
    {
        RentalOrder::query()->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle?->id,
            'start_date' => $date,
            'end_date' => $date,
            'total' => $total,
            'outside_cost' => 0.0,
            'state' => RentalOrder::STATE_CLOSED,
            'payment_status' => RentalOrder::PAYMENT_PAID,
        ]);
    }

    private function car(string $name, string $plate, float $target = 0.0): Vehicle
    {
        return Vehicle::query()->create([
            'name' => $name,
            'plate_no' => $plate,
            'monthly_target' => $target,
            'active' => true,
            'is_outside' => false,
        ]);
    }

    /** Jobs every $gap days, counting back from $daysAgo. */
    private function rhythmOf(RentalCustomer $customer, int $gap, int $count, int $daysAgo, float $each = 100.0): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->order($customer, $this->today->subDays($daysAgo + $i * $gap)->toDateString(), $each);
        }
    }

    // ── Where the money came from ────────────────────────────────────────────

    public function test_the_schedule_adds_up_to_the_figure_in_the_box_above_it(): void
    {
        // A breakdown that does not reconcile with its own headline teaches
        // people to distrust both, so this is the property that matters most.
        $a = $this->car('Sunny', '111111');
        $b = $this->car('Yaris', '222222');
        $c = $this->customer('Qassim');

        $this->order($c, '2026-09-02', 300, $a);
        $this->order($c, '2026-09-09', 200, $b);
        $this->order($c, '2026-09-11', 150, null);

        $earned = app(RevenueTargets::class)->earned('rental', $this->today->startOfMonth(), $this->today->endOfMonth());
        $schedule = app(RevenueSchedule::class)->forWindow('rental', $this->today->startOfMonth(), $this->today->endOfMonth());

        $this->assertEqualsWithDelta(650.0, $earned, 0.001);
        $this->assertEqualsWithDelta($earned, $schedule['total'], 0.001);
        $this->assertEqualsWithDelta($earned, array_sum(array_column($schedule['rows'], 'amount')) + $schedule['others'], 0.001);
    }

    public function test_money_earned_against_no_car_is_still_shown(): void
    {
        // It is real money. Dropping the row would make the schedule disagree
        // with the box, which is how a breakdown starts lying.
        $this->order($this->customer('Qassim'), '2026-09-02', 150, null);

        $schedule = app(RevenueSchedule::class)->forWindow('rental', $this->today->startOfMonth(), $this->today->endOfMonth());

        $this->assertSame('No car recorded', $schedule['rows'][0]['label']);
        $this->assertEqualsWithDelta(150.0, $schedule['rows'][0]['amount'], 0.001);
    }

    public function test_a_car_row_is_scored_against_that_cars_own_target(): void
    {
        $car = $this->car('Sunny', '111111', 400.0);
        $this->order($this->customer('Qassim'), '2026-09-02', 300, $car);

        $month = app(RevenueSchedule::class)->forWindow('rental', $this->today->startOfMonth(), $this->today->endOfMonth());
        $this->assertSame(75, $month['rows'][0]['pct']);

        // Over a year the car's MONTHLY target is judged twelve times over.
        $year = app(RevenueSchedule::class)->forWindow('rental', $this->today->startOfYear(), $this->today->endOfYear(), 12.0);
        $this->assertEqualsWithDelta(4800.0, $year['rows'][0]['target'], 0.001);
    }

    public function test_the_limousine_schedule_groups_by_car_type(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Dadabhai', 'type' => 'company']);

        foreach ([['sedan', 100.0], ['suv', 250.0], ['sedan', 50.0]] as [$type, $fare]) {
            LimoBooking::query()->create([
                'customer_id' => $customer->id,
                'pickup_at' => '2026-09-05 10:00:00',
                'car_type' => $type,
                'fare' => $fare,
                'amount' => $fare,
                'status' => LimoBooking::STATUS_COMPLETED,
                'payment_status' => LimoBooking::PAYMENT_PAID,
            ]);
        }

        $schedule = app(RevenueSchedule::class)->forWindow('limousine', $this->today->startOfMonth(), $this->today->endOfMonth());

        $this->assertSame('Suv', $schedule['rows'][0]['label']);
        $this->assertEqualsWithDelta(250.0, $schedule['rows'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(150.0, $schedule['rows'][1]['amount'], 0.001);
        // No vehicle register, so no per-row target to score against.
        $this->assertNull($schedule['rows'][1]['target']);
    }

    // ── Where the target came from ───────────────────────────────────────────

    public function test_the_monthly_target_falls_back_to_the_cars_own_targets(): void
    {
        $this->car('Sunny', '111111', 300.0);
        $this->car('Yaris', '222222', 200.0);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertEqualsWithDelta(500.0, $progress['month']['target'], 0.001);
        $this->assertSame('fleet', $progress['month']['source']);
        $this->assertSame(2, $progress['fleet']['cars']);
        // A year derived from a monthly figure is only ever an estimate.
        $this->assertEqualsWithDelta(6000.0, $progress['year']['target'], 0.001);
        $this->assertSame('fleet_estimate', $progress['year']['source']);
    }

    public function test_what_the_owner_typed_beats_what_the_cars_add_up_to(): void
    {
        $this->car('Sunny', '111111', 300.0);
        app(RevenueTargets::class)->set('rental', 900.0, null);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertEqualsWithDelta(900.0, $progress['month']['target'], 0.001);
        $this->assertSame('typed', $progress['month']['source']);
        // The yearly was left blank, so it still falls back to the fleet.
        $this->assertSame('fleet_estimate', $progress['year']['source']);
    }

    public function test_a_rented_in_or_retired_car_carries_no_fleet_target(): void
    {
        // An outside car earns for its owner, and a retired one should not
        // still be expected to bring money in.
        $this->car('Ours', '111111', 300.0);
        Vehicle::query()->create(['name' => 'Hired in', 'plate_no' => '333333', 'monthly_target' => 999.0, 'active' => true, 'is_outside' => true]);
        Vehicle::query()->create(['name' => 'Retired', 'plate_no' => '444444', 'monthly_target' => 888.0, 'active' => false, 'is_outside' => false]);

        $this->assertEqualsWithDelta(300.0, app(RevenueTargets::class)->fleet('rental')['total'], 0.001);
    }

    public function test_limousine_has_no_fleet_to_derive_a_target_from(): void
    {
        $this->assertNull(app(RevenueTargets::class)->fleet('limousine'));
        $this->assertNull(app(RevenueTargets::class)->progress('limousine', $this->today)['month']['source']);
    }

    // ── Who pays us, and who has stopped ─────────────────────────────────────

    public function test_customers_are_ranked_by_what_they_actually_paid(): void
    {
        $big = $this->customer('Dadabhai');
        $small = $this->customer('Walk-in');

        $this->order($big, '2026-08-01', 900);
        $this->order($small, '2026-08-01', 100);

        $top = app(TopCustomers::class)->forApp('rental', $this->today);

        $this->assertSame('Dadabhai', $top['rows'][0]['name']);
        $this->assertEqualsWithDelta(900.0, $top['rows'][0]['paid'], 0.001);
        $this->assertSame(90, $top['rows'][0]['share']);
    }

    public function test_a_customers_rhythm_is_the_median_gap_not_the_average(): void
    {
        // One long break in an otherwise fortnightly customer would drag a mean
        // far enough to excuse almost any silence. The median ignores it.
        $c = $this->customer('Fortnightly');
        foreach ([400, 300, 286, 272, 258] as $daysAgo) {
            $this->order($c, $this->today->subDays($daysAgo)->toDateString(), 100);
        }

        $row = app(TopCustomers::class)->forApp('rental', $this->today)['rows'][0];

        // Gaps are 100, 14, 14, 14 - the mean is 35, the median is 14.
        $this->assertSame(14, $row['rhythm']);
    }

    public function test_a_regular_customer_who_has_stopped_is_flagged(): void
    {
        // Books every 14 days and has not called in 60: a real problem.
        $c = $this->customer('Every fortnight');
        $this->rhythmOf($c, 14, 6, 60);

        $row = app(TopCustomers::class)->forApp('rental', $this->today)['rows'][0];

        $this->assertSame(14, $row['rhythm']);
        $this->assertSame(60, $row['daysSince']);
        $this->assertSame('lost', $row['status']);
        $this->assertSame(46, $row['overdueBy']);
    }

    public function test_an_occasional_customer_quiet_for_the_same_time_is_not(): void
    {
        // Also silent for 60 days, but they only ever book twice a year, so
        // they are behaving exactly as they always have. A single company-wide
        // cut-off would wrongly put this one on the call list.
        $c = $this->customer('Twice a year');
        $this->rhythmOf($c, 180, 4, 60);

        $row = app(TopCustomers::class)->forApp('rental', $this->today)['rows'][0];

        $this->assertSame(180, $row['rhythm']);
        $this->assertSame(60, $row['daysSince']);
        $this->assertSame('active', $row['status']);
    }

    public function test_a_customer_slightly_past_their_own_gap_is_worth_a_call(): void
    {
        $c = $this->customer('Slipping');
        $this->rhythmOf($c, 20, 5, 30);

        $row = app(TopCustomers::class)->forApp('rental', $this->today)['rows'][0];

        $this->assertSame('slipping', $row['status']);
    }

    public function test_a_very_frequent_customer_gets_a_few_days_grace(): void
    {
        // Books daily. Without a floor, being one day late would read as
        // "lost" and the call sheet would cry wolf every morning.
        $c = $this->customer('Daily');
        $this->rhythmOf($c, 1, 10, 3);

        $row = app(TopCustomers::class)->forApp('rental', $this->today)['rows'][0];

        $this->assertSame('active', $row['status']);
    }

    public function test_one_job_is_not_a_rhythm(): void
    {
        $c = $this->customer('First timer');
        $this->order($c, $this->today->subDays(10)->toDateString(), 500);

        $row = app(TopCustomers::class)->forApp('rental', $this->today)['rows'][0];

        $this->assertNull($row['rhythm']);
        $this->assertSame('new', $row['status']);
    }

    public function test_the_rhythm_reads_the_whole_history_not_just_the_ranking_window(): void
    {
        // A customer of years would otherwise be called "new" because the
        // window only caught their most recent job.
        $c = $this->customer('Old friend');
        foreach ([700, 670, 640, 610, 20] as $daysAgo) {
            $this->order($c, $this->today->subDays($daysAgo)->toDateString(), 100);
        }

        $row = app(TopCustomers::class)->forApp('rental', $this->today)['rows'][0];

        $this->assertSame(30, $row['rhythm']);
        $this->assertSame(20, $row['daysSince']);
        $this->assertNotSame('new', $row['status']);
    }

    public function test_the_panel_totals_what_is_at_risk(): void
    {
        $quiet = $this->customer('Gone quiet');
        $this->rhythmOf($quiet, 14, 6, 90, 200.0);

        $fine = $this->customer('Still here');
        $this->rhythmOf($fine, 14, 6, 3, 100.0);

        $top = app(TopCustomers::class)->forApp('rental', $this->today);

        $this->assertSame(1, $top['quiet']);
        $this->assertEqualsWithDelta(1200.0, $top['atRisk'], 0.001);
    }

    public function test_unpaid_work_and_anonymous_jobs_do_not_rank_anyone(): void
    {
        $c = $this->customer('Owes us');
        RentalOrder::query()->create([
            'customer_id' => $c->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'total' => 900,
            'outside_cost' => 0.0,
            'state' => RentalOrder::STATE_ACTIVE,
            'payment_status' => RentalOrder::PAYMENT_UNPAID,
        ]);
        // A job with no customer is money we cannot chase.
        $this->order($this->customer('Real'), '2026-09-01', 100);
        RentalOrder::query()->create([
            'customer_id' => null,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'total' => 400,
            'outside_cost' => 0.0,
            'state' => RentalOrder::STATE_CLOSED,
            'payment_status' => RentalOrder::PAYMENT_PAID,
        ]);

        $top = app(TopCustomers::class)->forApp('rental', $this->today);

        $this->assertCount(1, $top['rows']);
        $this->assertSame('Real', $top['rows'][0]['name']);
        // Anonymous money still counts in the total it is a share of.
        $this->assertEqualsWithDelta(500.0, $top['collected'], 0.001);
        $this->assertSame(20, $top['rows'][0]['share']);
    }

    public function test_the_list_stops_at_fifteen(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->order($this->customer('Customer '.$i), '2026-09-01', (float) (100 * $i));
        }

        $top = app(TopCustomers::class)->forApp('rental', $this->today);

        $this->assertCount(15, $top['rows']);
        $this->assertSame('Customer 20', $top['rows'][0]['name']);
    }

    // ── On the dashboards ────────────────────────────────────────────────────

    public function test_the_owner_sees_the_call_sheet_on_both_dashboards(): void
    {
        $c = $this->customer('Dadabhai');
        $this->rhythmOf($c, 14, 6, 90, 200.0);
        $this->actingAs($this->owner());

        Livewire::test(RentalHome::class)
            ->assertSee('Customers to focus on')
            ->assertSee('Dadabhai')
            ->assertSee('Going quiet');

        Livewire::test(LimoHome::class)->assertSee('Customers to focus on');
    }

    public function test_a_regular_admin_sees_neither_the_schedule_nor_the_call_sheet(): void
    {
        // Both are lists of what named customers and named cars earn, so they
        // are the owner's for exactly the same reason the revenue card is.
        $car = $this->car('Sunny', '111111', 400.0);
        $this->order($this->customer('Dadabhai'), '2026-09-02', 300, $car);
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));

        Livewire::test(RentalHome::class)
            ->assertDontSee('Customers to focus on')
            ->assertDontSee('Where it came from')
            ->assertDontSee('Dadabhai');
    }

    public function test_the_schedule_is_on_the_dashboard_and_names_its_biggest_source(): void
    {
        // The biggest single source reads without opening anything, so the
        // panel is worth something before anyone thinks to click it.
        $car = $this->car('Sunny', '111111', 400.0);
        $this->order($this->customer('Qassim'), '2026-09-02', 300, $car);
        $this->actingAs($this->owner());

        Livewire::test(RentalHome::class)
            ->assertSee('Where it came from')
            ->assertSee('Sunny · 111111');
    }

    public function test_a_period_with_no_money_says_so_instead_of_vanishing(): void
    {
        // An earlier version rendered nothing at all here, which is
        // indistinguishable from a feature that was never built - the owner
        // went looking for the breakdown and could not find it.
        $this->actingAs($this->owner());

        Livewire::test(RentalHome::class)
            ->assertSee('Where it came from')
            ->assertSee('Nothing collected in this period yet.');

        Livewire::test(LimoHome::class)
            ->assertSee('Where it came from')
            ->assertSee('Nothing collected in this period yet.');
    }
}

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

    private function customerNamed(string $name): RentalCustomer
    {
        return $this->customer($name);
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

    public function test_a_limousine_booking_with_no_leg_is_still_counted(): void
    {
        // The breakdown used to group by `car_type`, which no import ever
        // filled, so a whole year landed in one row reading "No car type
        // recorded, 100%". It groups by the kind of WORK now - but a booking
        // with no leg at all still has to appear, or the rows would stop
        // adding up to the box above them.
        $customer = LimoCustomer::query()->create(['name' => 'Dadabhai', 'type' => 'company']);

        LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => '2026-09-05 10:00:00',
            'fare' => 400.0,
            'amount' => 400.0,
            'status' => LimoBooking::STATUS_COMPLETED,
            'payment_status' => LimoBooking::PAYMENT_PAID,
        ]);

        $schedule = app(RevenueSchedule::class)->forWindow('limousine', $this->today->startOfMonth(), $this->today->endOfMonth());
        $earned = app(RevenueTargets::class)->earned('limousine', $this->today->startOfMonth(), $this->today->endOfMonth());

        $this->assertSame('Not recorded', $schedule['rows'][0]['label']);
        $this->assertEqualsWithDelta(400.0, $schedule['rows'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta($earned, $schedule['total'], 0.001);
        // No vehicle register, so no per-row target to score against.
        $this->assertNull($schedule['rows'][0]['target']);
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
        $this->assertSame(2, $progress['fleet']['fleet']);
        // No yearly target is invented from the monthly one: twelve equal
        // months is not how this trade runs.
        $this->assertNull($progress['year']['target']);
        $this->assertNull($progress['year']['source']);
    }

    public function test_what_the_owner_typed_beats_what_the_cars_add_up_to(): void
    {
        $this->car('Sunny', '111111', 300.0);
        app(RevenueTargets::class)->set('rental', 900.0, null);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertEqualsWithDelta(900.0, $progress['month']['target'], 0.001);
        $this->assertSame('typed', $progress['month']['source']);
        // The yearly was left blank, and blank stays blank.
        $this->assertNull($progress['year']['target']);
    }

    public function test_a_fleet_target_needs_every_car_to_carry_one(): void
    {
        // The owner's screen said "Added up from 21 cars' own monthly targets"
        // when one car had a target, then called the resulting 150 BD a month
        // met. A partial sum is not a fleet target.
        $this->car('Sunny', '111111', 150.0);
        $this->car('Yaris', '222222', 0.0);

        $progress = app(RevenueTargets::class)->progress('rental', $this->today);

        $this->assertNull($progress['month']['target']);
        $this->assertNull($progress['month']['source']);
        $this->assertSame(1, $progress['fleet']['cars']);
        $this->assertSame(2, $progress['fleet']['fleet']);
    }

    public function test_the_box_says_how_many_cars_still_need_a_target(): void
    {
        $this->car('Sunny', '111111', 150.0);
        $this->car('Yaris', '222222', 0.0);
        $this->actingAs($this->owner());

        Livewire::test(RentalHome::class)
            ->assertSee('Only 1 of your 2 cars have a monthly target')
            ->assertDontSee('Added up from');
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

    /** @return array<string, mixed> */
    private function row(string $name, string $group = 'individual'): array
    {
        foreach (app(TopCustomers::class)->forApp('rental', $this->today)['groups'][$group]['rows'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        $this->fail("No {$group} row for {$name}");
    }

    public function test_companies_and_individuals_are_ranked_apart(): void
    {
        // A handful of corporate accounts would otherwise crowd out every
        // individual and half the business would never be looked at.
        $company = RentalCustomer::query()->create(['name' => 'Dadabhai', 'type' => 'company', 'phone' => '3901']);
        $person = RentalCustomer::query()->create(['name' => 'Qassim', 'type' => 'individual', 'phone' => '3902']);

        $this->order($company, '2026-08-01', 5000);
        $this->order($person, '2026-08-01', 100);

        $groups = app(TopCustomers::class)->forApp('rental', $this->today)['groups'];

        $this->assertSame('Dadabhai', $groups['company']['rows'][0]['name']);
        $this->assertCount(1, $groups['company']['rows']);
        $this->assertSame('Qassim', $groups['individual']['rows'][0]['name']);
        $this->assertCount(1, $groups['individual']['rows']);
    }

    public function test_a_customer_with_no_type_is_treated_as_a_person(): void
    {
        // A blank type on an imported row has to land somewhere, not vanish.
        $blank = RentalCustomer::query()->create(['name' => 'No type', 'type' => '', 'phone' => '3903']);
        $this->order($blank, '2026-08-01', 400);

        $groups = app(TopCustomers::class)->forApp('rental', $this->today)['groups'];

        $this->assertSame('No type', $groups['individual']['rows'][0]['name']);
        $this->assertSame([], $groups['company']['rows']);
    }

    public function test_only_the_last_six_months_count(): void
    {
        // Over a year the list filled with people last seen ten months ago,
        // which is a list of strangers rather than a call sheet.
        $this->order($this->customer('Still here'), '2026-08-01', 100);
        // Ten months back: inside the old twelve-month window, outside this one.
        $this->order($this->customerNamed('Long gone'), '2025-11-15', 9000);

        $rows = app(TopCustomers::class)->forApp('rental', $this->today)['groups']['individual']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('Still here', $rows[0]['name']);
    }

    public function test_the_window_is_six_whole_months_ending_this_one(): void
    {
        $report = app(TopCustomers::class)->forApp('rental', $this->today);

        $this->assertSame(6, $report['windowMonths']);
        $this->assertCount(6, $report['months']);
        $this->assertSame('2026-04', $report['months'][0]['key']);
        $this->assertSame('2026-09', $report['months'][5]['key']);
        $this->assertSame('2026-04-01', $report['from']);
    }

    public function test_each_row_carries_its_month_by_month_record(): void
    {
        // What turns "going quiet" from a claim into something visible.
        $c = $this->customer('Monthly');
        $this->order($c, '2026-05-10', 120);
        $this->order($c, '2026-05-20', 80);
        $this->order($c, '2026-08-02', 300);

        $row = $this->row('Monthly');

        $this->assertEqualsWithDelta(200.0, $row['months']['2026-05'], 0.001);
        $this->assertEqualsWithDelta(300.0, $row['months']['2026-08'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['months']['2026-06'], 0.001);
        $this->assertEqualsWithDelta(500.0, $row['paid'], 0.001);
    }

    public function test_customers_are_ranked_by_what_they_actually_paid(): void
    {
        $this->order($this->customer('Dadabhai'), '2026-08-01', 900);
        $this->order($this->customerNamed('Walk-in'), '2026-08-01', 100);

        $rows = app(TopCustomers::class)->forApp('rental', $this->today)['groups']['individual']['rows'];

        $this->assertSame('Dadabhai', $rows[0]['name']);
        $this->assertEqualsWithDelta(900.0, $rows[0]['paid'], 0.001);
        $this->assertSame(90, $rows[0]['share']);
    }

    public function test_a_customers_rhythm_is_the_median_gap_not_the_average(): void
    {
        // One long break in an otherwise fortnightly customer would drag a mean
        // far enough to excuse almost any silence. The median ignores it.
        $c = $this->customer('Fortnightly');
        foreach ([400, 300, 286, 272, 60] as $daysAgo) {
            $this->order($c, $this->today->subDays($daysAgo)->toDateString(), 100);
        }

        // Gaps are 100, 14, 14, 212 - the mean is 85, the median is 57.
        $this->assertSame(57, $this->row('Fortnightly')['rhythm']);
    }

    public function test_the_rhythm_still_reads_the_whole_history(): void
    {
        // Six months of ranking, but a customer of years must not read as new
        // because the window only caught their most recent job.
        $c = $this->customer('Old friend');
        foreach ([700, 670, 640, 610, 20] as $daysAgo) {
            $this->order($c, $this->today->subDays($daysAgo)->toDateString(), 100);
        }

        $row = $this->row('Old friend');

        $this->assertSame(30, $row['rhythm']);
        $this->assertNotSame('new', $row['status']);
    }

    public function test_a_regular_customer_who_has_stopped_is_flagged(): void
    {
        $c = $this->customer('Every fortnight');
        $this->rhythmOf($c, 14, 6, 60);

        $row = $this->row('Every fortnight');

        $this->assertSame(14, $row['rhythm']);
        $this->assertSame(60, $row['daysSince']);
        $this->assertSame('lost', $row['status']);
        $this->assertSame(46, $row['overdueBy']);
    }

    public function test_an_occasional_customer_quiet_for_the_same_time_is_not(): void
    {
        // Also silent for 60 days, but they only ever book twice a year, so a
        // single company-wide cut-off would wrongly put them on the call list.
        $c = $this->customer('Twice a year');
        $this->rhythmOf($c, 180, 4, 60);

        $row = $this->row('Twice a year');

        $this->assertSame(180, $row['rhythm']);
        $this->assertSame('active', $row['status']);
    }

    public function test_a_very_frequent_customer_gets_a_few_days_grace(): void
    {
        // Without a floor, being one day late would read as "lost" and the call
        // sheet would cry wolf every morning.
        $c = $this->customer('Daily');
        $this->rhythmOf($c, 1, 10, 3);

        $this->assertSame('active', $this->row('Daily')['status']);
    }

    public function test_one_job_is_not_a_rhythm(): void
    {
        $c = $this->customer('First timer');
        $this->order($c, $this->today->subDays(10)->toDateString(), 500);

        $row = $this->row('First timer');

        $this->assertNull($row['rhythm']);
        $this->assertSame('new', $row['status']);
    }

    public function test_the_group_totals_what_is_at_risk(): void
    {
        $this->rhythmOf($this->customer('Gone quiet'), 14, 4, 90, 200.0);
        $this->rhythmOf($this->customerNamed('Still here'), 14, 4, 3, 100.0);

        $group = app(TopCustomers::class)->forApp('rental', $this->today)['groups']['individual'];

        $this->assertSame(1, $group['quiet']);
        $this->assertEqualsWithDelta(800.0, $group['atRisk'], 0.001);
    }

    public function test_unpaid_work_and_anonymous_jobs_do_not_rank_anyone(): void
    {
        $owes = $this->customer('Owes us');
        RentalOrder::query()->create([
            'customer_id' => $owes->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'total' => 900,
            'outside_cost' => 0.0,
            'state' => RentalOrder::STATE_ACTIVE,
            'payment_status' => RentalOrder::PAYMENT_UNPAID,
        ]);
        $this->order($this->customerNamed('Real'), '2026-09-01', 100);
        // A job with no customer is money we cannot chase.
        RentalOrder::query()->create([
            'customer_id' => null,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'total' => 400,
            'outside_cost' => 0.0,
            'state' => RentalOrder::STATE_CLOSED,
            'payment_status' => RentalOrder::PAYMENT_PAID,
        ]);

        $report = app(TopCustomers::class)->forApp('rental', $this->today);
        $rows = $report['groups']['individual']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('Real', $rows[0]['name']);
        // Anonymous money still counts in the total it is a share of.
        $this->assertEqualsWithDelta(500.0, $report['collected'], 0.001);
        $this->assertSame(20, $rows[0]['share']);
    }

    public function test_each_group_stops_at_fifteen(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->order($this->customerNamed('Person '.$i), '2026-08-01', (float) (100 * $i));
        }

        $rows = app(TopCustomers::class)->forApp('rental', $this->today)['groups']['individual']['rows'];

        $this->assertCount(15, $rows);
        $this->assertSame('Person 20', $rows[0]['name']);
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

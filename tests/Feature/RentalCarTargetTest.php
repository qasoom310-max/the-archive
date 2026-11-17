<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Targets\CarTargets;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\VehicleForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Each car carries a monthly sales target (BHD); its page tracks this month's
 * actual earnings against it.
 */
final class RentalCarTargetTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');

        // Bespoke rental screens are ACL-gated; these tests are about the role
        // rules on top of that, so put the user where a granted staff account is.
        $this->grantEveryone('rental.vehicle');
    }

    private function orderFor(Vehicle $car, float $total, Carbon $start, string $state = RentalOrder::STATE_CLOSED): RentalOrder
    {
        return RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'C'])->id,
            'vehicle_id' => $car->id,
            'start_date' => $start, 'end_date' => $start->copy()->addDay(),
            'rate_type' => 'daily', 'rate' => $total, 'total' => $total, 'state' => $state,
        ]);
    }

    public function test_revenue_this_month_sums_this_months_non_cancelled_orders(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'monthly_target' => 500]);

        $this->orderFor($car, 100, Carbon::now()->startOfMonth()->addDays(2));        // counts
        $this->orderFor($car, 150, Carbon::now()->startOfMonth()->addDays(5));        // counts
        // NoOverflow: on the 31st, subMonth() is "the 31st of last month", which
        // a 30-day month rolls over to the 1st of THIS month — inside the window.
        $this->orderFor($car, 999, Carbon::now()->subMonthNoOverflow());              // other month — excluded
        $this->orderFor($car, 80, Carbon::now()->addDays(1), RentalOrder::STATE_CANCELLED); // cancelled — excluded

        $this->assertSame(250.0, $car->revenueThisMonth());
    }

    public function test_the_car_page_shows_the_target_and_progress(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport']);
        app(CarTargets::class)->set($car->id, (int) Carbon::now()->year, 6000);
        $this->orderFor($car, 200, Carbon::now()->startOfMonth()->addDays(2));

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->assertSee('This month (')  // the progress card (unique to it)
            ->assertSee('200')     // earned this month
            ->assertSee('500')     // this month's share: a twelfth of the year
            ->assertSee('6,000');  // the year
    }

    public function test_a_manager_sets_the_target_inline_and_the_bar_appears(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport']); // no target yet

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->assertDontSee('This month (') // no bar before a target
            ->set('yearlyTargetInput', '9000')
            ->call('saveTarget')
            ->assertHasNoErrors()
            ->assertSee('This month (');     // bar now shows

        $this->assertSame(9000.0, (new CarTargets())->yearly($car->id, (int) Carbon::now()->year));
    }

    public function test_a_non_manager_cannot_set_the_target(): void
    {
        $this->actingAs(User::factory()->create()); // plain staff
        $car = Vehicle::query()->create(['name' => 'Eco Sport']);

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->set('yearlyTargetInput', '9000')
            ->call('saveTarget')
            ->assertForbidden();

        $this->assertSame(0.0, (new CarTargets())->yearly($car->id, (int) Carbon::now()->year));
    }

    public function test_no_target_card_when_target_is_zero(): void
    {
        // The box to type a target is always there for a manager; only the
        // progress bars are gated on a target being set.
        $car = Vehicle::query()->create(['name' => 'No Target']);

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->assertDontSee('This month (');
    }

    // ── One target a year; the rest is worked out ────────────────────────────

    public function test_a_reached_target_starts_the_next_year_ten_percent_higher(): void
    {
        Carbon::setTestNow('2027-02-10 10:00:00');
        $car = Vehicle::query()->create(['name' => 'Reached']);
        (new CarTargets())->set($car->id, 2026, 15000);
        $this->orderFor($car, 15000, Carbon::parse('2026-06-01'));

        $targets = new CarTargets();
        $this->assertSame(15000.0, $targets->yearly($car->id, 2026));
        $this->assertSame(16500.0, $targets->yearly($car->id, 2027));
        $this->assertSame('raised', $targets->resolve($car->id, 2027)['source']);

        Livewire::test(VehicleForm::class, ['id' => $car->id])->assertSee('Raised automatically');
        Carbon::setTestNow();
    }

    public function test_a_missed_target_stays_the_same_the_next_year(): void
    {
        Carbon::setTestNow('2027-02-10 10:00:00');
        $car = Vehicle::query()->create(['name' => 'Missed']);
        (new CarTargets())->set($car->id, 2026, 15000);
        $this->orderFor($car, 14999, Carbon::parse('2026-06-01'));
        // A cancelled hire is not work done and cannot tip it over.
        $this->orderFor($car, 500, Carbon::parse('2026-07-01'), RentalOrder::STATE_CANCELLED);

        $targets = new CarTargets();
        $this->assertSame(15000.0, $targets->yearly($car->id, 2027));
        $this->assertSame('kept', $targets->resolve($car->id, 2027)['source']);
        Carbon::setTestNow();
    }

    public function test_the_raise_repeats_each_year_and_a_typed_figure_restarts_it(): void
    {
        Carbon::setTestNow('2029-03-01 10:00:00');
        $car = Vehicle::query()->create(['name' => 'Chain']);
        (new CarTargets())->set($car->id, 2026, 10000);
        $this->orderFor($car, 10000, Carbon::parse('2026-03-01'));   // reached
        $this->orderFor($car, 5000, Carbon::parse('2027-03-01'));    // missed 11,000
        $this->orderFor($car, 11000, Carbon::parse('2028-03-01'));   // reached 11,000

        $targets = new CarTargets();
        $this->assertSame(11000.0, $targets->yearly($car->id, 2027));
        $this->assertSame(11000.0, $targets->yearly($car->id, 2028));
        $this->assertSame(12100.0, $targets->yearly($car->id, 2029));

        // The manager types 2028 by hand: that is the new starting point.
        $targets->set($car->id, 2028, 20000);
        $this->assertSame(20000.0, $targets->yearly($car->id, 2028));
        $this->assertSame(20000.0, $targets->yearly($car->id, 2029)); // 11,000 earned: missed
        Carbon::setTestNow();
    }

    public function test_a_year_still_running_does_not_raise_the_one_after_it(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        $car = Vehicle::query()->create(['name' => 'Early']);
        (new CarTargets())->set($car->id, 2026, 1000);
        $this->orderFor($car, 5000, Carbon::parse('2026-03-01'));

        $this->assertSame(1000.0, (new CarTargets())->yearly($car->id, 2027));
        Carbon::setTestNow();
    }

    public function test_the_months_follow_how_the_fleet_sold_last_year(): void
    {
        $a = Vehicle::query()->create(['name' => 'A']);
        $b = Vehicle::query()->create(['name' => 'B']);
        $outside = Vehicle::query()->create(['name' => 'Rented in', 'is_outside' => true]);

        // Last year the fleet earned 100 in every month but June (300) and
        // July (500): 1,800 in all. Only car B worked in June and July.
        foreach (range(1, 12) as $m) {
            $this->orderFor($a, $m === 6 || $m === 7 ? 0.0 : 100, Carbon::create(2025, $m, 5));
        }
        $this->orderFor($b, 300, Carbon::create(2025, 6, 5));
        $this->orderFor($b, 500, Carbon::create(2025, 7, 5));
        // An outside car's sales never shape our months.
        $this->orderFor($outside, 9000, Carbon::create(2025, 1, 5));

        $targets = new CarTargets();
        $targets->set($a->id, 2026, 18000);

        $months = $targets->months($a->id, 2026);
        $this->assertFalse($targets->shares(2026)['equal']);
        $this->assertSame(1000.0, $months[1]);
        $this->assertSame(3000.0, $months[6]);
        $this->assertSame(5000.0, $months[7]);
        $this->assertEqualsWithDelta(18000.0, array_sum($months), 0.0001);
    }

    public function test_an_incomplete_last_year_falls_back_to_equal_months(): void
    {
        $car = Vehicle::query()->create(['name' => 'New']);
        $this->orderFor($car, 700, Carbon::create(2025, 9, 5)); // only one month on file

        $targets = new CarTargets();
        $targets->set($car->id, 2026, 12000);

        $this->assertTrue($targets->shares(2026)['equal']);
        $this->assertSame(1000.0, $targets->monthly($car->id, 2026, 3));
    }

    public function test_the_old_figures_are_read_until_a_target_is_typed(): void
    {
        $monthlyOnly = Vehicle::query()->create(['name' => 'Old monthly', 'monthly_target' => 500]);
        $both = Vehicle::query()->create(['name' => 'Old both', 'monthly_target' => 500, 'yearly_target' => 9000]);

        $targets = new CarTargets();
        $this->assertSame(6000.0, $targets->yearly($monthlyOnly->id, 2026));
        $this->assertSame(9000.0, $targets->yearly($both->id, 2026));
    }
}

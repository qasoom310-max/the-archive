<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\Reports;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The Targets lens on the reports page shows, per car, whether it hit its
 * monthly target (or the yearly target = monthly × 12) for the chosen period.
 */
final class RentalTargetsReportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function orderFor(Vehicle $car, float $total, Carbon $start): void
    {
        RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'C'])->id,
            'vehicle_id' => $car->id,
            'start_date' => $start, 'end_date' => $start->copy()->addDay(),
            'rate_type' => 'daily', 'rate' => $total, 'total' => $total,
            'state' => RentalOrder::STATE_CLOSED,
        ]);
    }

    public function test_monthly_lens_marks_cars_achieved_or_missed(): void
    {
        $hit = Vehicle::query()->create(['name' => 'HitCar', 'plate_no' => 'HIT1', 'daily_rate' => 10, 'monthly_target' => 80]);
        $miss = Vehicle::query()->create(['name' => 'MissCar', 'plate_no' => 'MISS1', 'daily_rate' => 10, 'monthly_target' => 200]);
        Vehicle::query()->create(['name' => 'NoTargetCar', 'plate_no' => 'NONE1', 'daily_rate' => 10]); // no target

        $this->orderFor($hit, 100, Carbon::create(2026, 3, 10));  // 100 >= 80 → achieved
        $this->orderFor($miss, 50, Carbon::create(2026, 3, 12));  // 50 < 200 → missed

        Livewire::test(Reports::class)
            ->set('tab', 'targets')
            ->set('targetYear', 2026)
            ->set('targetMonth', 3)
            ->assertViewHas('targets', fn (array $t): bool => $t['targetCount'] === 2 && $t['achievedCount'] === 1)
            ->assertSee('HitCar')
            ->assertSee('Achieved')
            ->assertSee('MissCar')
            ->assertSee('Missed')
            ->assertSee('No target'); // the untargeted car is listed but flagged
    }

    public function test_whole_year_lens_uses_twelve_times_the_monthly_target(): void
    {
        $car = Vehicle::query()->create(['name' => 'YearCar', 'plate_no' => 'YR1', 'daily_rate' => 10, 'monthly_target' => 100]);
        // 1,200 over the year exactly meets 100 × 12.
        $this->orderFor($car, 700, Carbon::create(2026, 2, 1));
        $this->orderFor($car, 500, Carbon::create(2026, 8, 1));

        Livewire::test(Reports::class)
            ->set('tab', 'targets')
            ->set('targetYear', 2026)
            ->set('targetMonth', 0) // whole year
            ->assertViewHas('targets', fn (array $t): bool => $t['wholeYear'] === true
                && $t['achievedCount'] === 1
                && $t['rows'][0]['expected'] === 1200.0);
    }

    public function test_outside_rented_in_cars_are_excluded_from_targets(): void
    {
        $ours = Vehicle::query()->create(['name' => 'OurCar', 'plate_no' => 'OUR1', 'daily_rate' => 10, 'monthly_target' => 50]);
        $outside = Vehicle::query()->create(['name' => 'OutsideCar', 'plate_no' => 'OUT1', 'daily_rate' => 10, 'monthly_target' => 50, 'is_outside' => true]);
        $this->orderFor($ours, 100, Carbon::create(2026, 3, 4));
        $this->orderFor($outside, 100, Carbon::create(2026, 3, 4));

        Livewire::test(Reports::class)
            ->set('tab', 'targets')
            ->set('targetYear', 2026)
            ->set('targetMonth', 3)
            ->assertSee('OurCar')
            ->assertDontSee('OutsideCar')
            ->assertViewHas('targets', fn (array $t): bool => $t['targetCount'] === 1);
    }

    public function test_a_cancelled_order_does_not_count_toward_the_target(): void
    {
        $car = Vehicle::query()->create(['name' => 'CancelCar', 'plate_no' => 'CN1', 'daily_rate' => 10, 'monthly_target' => 50]);
        RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'C'])->id,
            'vehicle_id' => $car->id,
            'start_date' => Carbon::create(2026, 3, 5), 'end_date' => Carbon::create(2026, 3, 6),
            'rate_type' => 'daily', 'rate' => 90, 'total' => 90,
            'state' => RentalOrder::STATE_CANCELLED,
        ]);

        Livewire::test(Reports::class)
            ->set('tab', 'targets')
            ->set('targetYear', 2026)
            ->set('targetMonth', 3)
            ->assertViewHas('targets', fn (array $t): bool => $t['achievedCount'] === 0);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\Sales;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The Sales page rebuilds the per-car booking-revenue matrix (Expected vs actual,
 * by year) and surfaces seasonal patterns.
 */
final class RentalSalesTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function order(Vehicle $car, float $total, string $date, string $state = RentalOrder::STATE_CLOSED): void
    {
        RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'C'])->id,
            'vehicle_id' => $car->id,
            'start_date' => Carbon::parse($date), 'end_date' => Carbon::parse($date)->addDay(),
            'rate_type' => 'daily', 'rate' => $total, 'total' => $total, 'state' => $state,
        ]);
    }

    public function test_the_matrix_shows_each_cars_monthly_revenue_and_expected(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'plate_no' => '203011', 'monthly_target' => 100]);
        $this->order($car, 90, '2026-03-10');                                  // March
        $this->order($car, 44, '2026-04-12');                                  // April
        $this->order($car, 33, '2026-06-01');                                  // June
        $this->order($car, 500, '2026-06-15', RentalOrder::STATE_CANCELLED);   // excluded
        $this->order($car, 999, '2025-06-01');                                 // other year — excluded from 2026

        Livewire::test(Sales::class, ['year' => 2026])
            ->assertSee('203011')          // the car
            ->assertSee('1200')            // expected = target 100 × 12
            ->assertSee('90')              // March revenue
            ->assertSee('44')              // April revenue
            ->assertSee('167');            // total 90 + 44 + 33
    }

    public function test_a_cancelled_order_is_excluded_from_the_year_total(): void
    {
        $car = Vehicle::query()->create(['name' => 'Yaris', 'monthly_target' => 0]);
        $this->order($car, 100, '2026-05-01');
        $this->order($car, 250, '2026-05-02', RentalOrder::STATE_CANCELLED);

        Livewire::test(Sales::class, ['year' => 2026])
            ->assertSee('Fleet total')
            ->assertSee('100')
            ->assertDontSee('350');
    }

    public function test_not_enough_history_shows_a_friendly_seasonality_note(): void
    {
        $car = Vehicle::query()->create(['name' => 'Yaris']);
        $this->order($car, 100, '2026-05-01'); // only one month of data

        Livewire::test(Sales::class, ['year' => 2026])
            ->assertSee('Not enough history yet');
    }
}

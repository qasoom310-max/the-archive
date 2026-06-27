<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Livewire\Sales;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * For a car rented in from outside, the vendor cost is recorded per booking and
 * netted out of revenue everywhere (markup, not the gross customer total).
 */
final class RentalOutsideRevenueTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    public function test_selecting_an_outside_car_suggests_the_vendor_cost_from_its_price(): void
    {
        $outside = Vehicle::query()->create(['name' => 'Patrol', 'daily_rate' => 50, 'is_outside' => true, 'purchase_price' => 30]);

        Livewire::test(OrderForm::class)
            ->set('vehicle_id', $outside->id)
            ->assertSet('outside_cost', '30'); // defaulted from the car
    }

    public function test_net_revenue_subtracts_the_vendor_cost(): void
    {
        $outside = Vehicle::query()->create(['name' => 'Patrol', 'plate_no' => '244139', 'is_outside' => true]);
        $order = RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'C'])->id,
            'vehicle_id' => $outside->id,
            'start_date' => Carbon::now()->startOfMonth()->addDay(), 'end_date' => Carbon::now()->addDays(2),
            'rate_type' => 'daily', 'rate' => 100, 'total' => 100, 'outside_cost' => 30,
            'state' => RentalOrder::STATE_CLOSED, 'payment_status' => RentalOrder::PAYMENT_PAID,
        ]);

        $this->assertSame(70.0, $order->netRevenue());              // 100 − 30
        $this->assertSame(70.0, $outside->fresh()?->revenueThisMonth());

        // Sales matrix shows the net (70), not the gross (100).
        Livewire::test(Sales::class, ['year' => (int) Carbon::now()->year])
            ->assertSee('244139')
            ->assertSee('70')
            ->assertDontSee('>100<'); // gross total not shown as the figure

        // Dashboard revenue is net too.
        Livewire::test(RentalHome::class)->assertViewHas('revenue', 70.0);
    }

    public function test_owned_cars_keep_full_revenue(): void
    {
        $owned = Vehicle::query()->create(['name' => 'Eco Sport', 'plate_no' => '203011']);
        RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'C'])->id,
            'vehicle_id' => $owned->id,
            'start_date' => Carbon::now()->startOfMonth()->addDay(), 'end_date' => Carbon::now()->addDays(2),
            'rate_type' => 'daily', 'rate' => 100, 'total' => 100,
            'state' => RentalOrder::STATE_CLOSED, 'payment_status' => RentalOrder::PAYMENT_PAID,
        ]);

        $this->assertSame(100.0, $owned->fresh()?->revenueThisMonth()); // no cost → full amount
        Livewire::test(RentalHome::class)->assertViewHas('revenue', 100.0);
    }
}

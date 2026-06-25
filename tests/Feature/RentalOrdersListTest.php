<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\Orders;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The orders list shows each car's plate number and colour beside the model so
 * cars of the same brand are told apart. (Regression: the eager-load must keep
 * selecting plate_no/color, not just id/name.)
 */
final class RentalOrdersListTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    public function test_the_list_shows_the_vehicle_plate_and_colour(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Qassim', 'phone' => '39000001']);
        $vehicle = Vehicle::query()->create([
            'name' => 'Eco Sport', 'make' => 'Ford', 'model' => 'Eco Sport',
            'plate_no' => '789456', 'color' => 'White', 'daily_rate' => 10,
        ]);
        RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'order_date' => Carbon::parse('2026-06-24'),
            'start_date' => Carbon::parse('2026-06-24'), 'end_date' => Carbon::parse('2026-06-25'),
            'rate_type' => 'daily', 'rate' => 10,
        ]);

        Livewire::test(Orders::class)
            ->assertSee('Eco Sport')
            ->assertSee('789456')   // plate number
            ->assertSee('White');   // colour
    }
}

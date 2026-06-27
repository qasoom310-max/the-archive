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

    public function test_saving_a_new_order_stamps_and_lists_the_creator(): void
    {
        $maker = User::factory()->create(['name' => 'Sara Customer-Service', 'is_admin' => true]);
        $this->actingAs($maker);

        $customer = RentalCustomer::query()->create(['name' => 'Buyer', 'phone' => '39000002']);
        $vehicle = Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10]);

        Livewire::test(\Modules\Rental\Livewire\OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', '2026-06-24')
            ->set('end_date', '2026-06-25')
            ->set('rate_type', 'daily')
            ->set('rate', '10')
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        $order = RentalOrder::query()->latest('id')->sole();
        $this->assertSame($maker->id, $order->created_by_user_id);

        Livewire::test(Orders::class)->assertSee('Sara Customer-Service'); // creator shows on the list
    }

    public function test_the_search_box_filters_by_customer_reference_and_plate(): void
    {
        $alpha = RentalCustomer::query()->create(['name' => 'Alpha Renter', 'phone' => '39000010']);
        $beta = RentalCustomer::query()->create(['name' => 'Beta Renter', 'phone' => '39000011']);
        $carA = Vehicle::query()->create(['name' => 'Patrol', 'plate_no' => 'AAA111', 'daily_rate' => 10]);
        $carB = Vehicle::query()->create(['name' => 'Sunny', 'plate_no' => 'BBB222', 'daily_rate' => 10]);

        RentalOrder::query()->create([
            'customer_id' => $alpha->id, 'vehicle_id' => $carA->id,
            'start_date' => Carbon::parse('2026-06-24'), 'end_date' => Carbon::parse('2026-06-25'),
            'rate_type' => 'daily', 'rate' => 10,
        ]);
        RentalOrder::query()->create([
            'customer_id' => $beta->id, 'vehicle_id' => $carB->id,
            'start_date' => Carbon::parse('2026-06-24'), 'end_date' => Carbon::parse('2026-06-25'),
            'rate_type' => 'daily', 'rate' => 10,
        ]);

        // By customer name.
        Livewire::test(Orders::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha Renter')
            ->assertDontSee('Beta Renter');

        // By plate number.
        Livewire::test(Orders::class)
            ->set('search', 'BBB222')
            ->assertSee('Beta Renter')
            ->assertDontSee('Alpha Renter');
    }
}

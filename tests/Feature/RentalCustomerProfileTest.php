<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\CustomerForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Customer 360: opening a customer shows their whole rental history (and spend)
 * on the customer page, so you don't have to dig through the orders list.
 */
final class RentalCustomerProfileTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    public function test_the_customer_page_lists_their_rental_orders_and_spend(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Qassim Makhlooq', 'phone' => '38467744']);
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'plate_no' => '203011', 'daily_rate' => 10]);
        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $car->id,
            'start_date' => Carbon::now(), 'end_date' => Carbon::now()->addDay(),
            'rate_type' => 'daily', 'rate' => 10,
        ]);

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->assertSee('Rental orders')
            ->assertSee($order->reference)   // their order shows on the customer page
            ->assertSee('203011')            // the car it was for
            ->assertSee('Total spend');
    }

    public function test_a_customer_with_no_history_shows_an_empty_state(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'New Person', 'phone' => '30000000']);

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->assertSee('No rental orders yet.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Booking date rules: the return date must be a later day than pick-up, and a
 * pick-up date in the past is only allowed for admins / super-admins.
 */
final class RentalOrderDatesTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');

        // Bespoke rental screens are ACL-gated; these tests are about the role
        // rules on top of that, so put the user where a granted staff account is.
        $this->grantEveryone('rental.order');
    }

    /** @return array{0: RentalCustomer, 1: Vehicle} */
    private function bookingTargets(): array
    {
        return [
            RentalCustomer::query()->create(['name' => 'Ali']),
            Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10]),
        ];
    }

    public function test_non_admin_cannot_book_a_past_pickup_date(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        [$customer, $vehicle] = $this->bookingTargets();

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', now()->subDay()->toDateString())
            ->set('end_date', now()->addDay()->toDateString())
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasErrors(['start_date']);

        $this->assertSame(0, RentalOrder::query()->count());
    }

    public function test_admin_can_backdate_a_booking(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$customer, $vehicle] = $this->bookingTargets();

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', now()->subDay()->toDateString())
            ->set('end_date', now()->addDay()->toDateString())
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, RentalOrder::query()->count());
    }

    public function test_return_date_must_be_after_pickup(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$customer, $vehicle] = $this->bookingTargets();

        // Same day → rejected (return must be a LATER day).
        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', now()->addDay()->toDateString())
            ->set('end_date', now()->addDay()->toDateString())
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasErrors(['end_date']);

        // Return before pick-up → rejected.
        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', now()->addDays(2)->toDateString())
            ->set('end_date', now()->addDay()->toDateString())
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasErrors(['end_date']);

        $this->assertSame(0, RentalOrder::query()->count());
    }

    public function test_a_valid_future_range_saves_for_anyone(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        [$customer, $vehicle] = $this->bookingTargets();

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', now()->addDay()->toDateString())
            ->set('end_date', now()->addDays(3)->toDateString())
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, RentalOrder::query()->count());
    }
}

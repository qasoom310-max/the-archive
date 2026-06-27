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
 * Reservations: a saved draft holds its car as Reserved, the status follows the
 * order through Rented → Available, and a vehicle can't be double-booked for
 * overlapping dates.
 */
final class RentalReservationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function customer(): RentalCustomer
    {
        return RentalCustomer::query()->create(['name' => 'Ali']);
    }

    private function vehicle(string $status = Vehicle::STATUS_AVAILABLE): Vehicle
    {
        return Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10, 'status' => $status]);
    }

    public function test_saving_a_draft_reserves_the_vehicle(): void
    {
        $customer = $this->customer();
        $vehicle = $this->vehicle();

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', '2026-08-01')
            ->set('end_date', '2026-08-03')
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(Vehicle::STATUS_RESERVED, $vehicle->fresh()?->status);
    }

    public function test_status_follows_reserved_to_rented_to_available(): void
    {
        $vehicle = $this->vehicle();
        $order = RentalOrder::query()->create([
            'customer_id' => $this->customer()->id, 'vehicle_id' => $vehicle->id,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-03',
        ]);

        $order->startRental();
        $this->assertSame(Vehicle::STATUS_RENTED, $vehicle->fresh()?->status);

        $order->closeRental();
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicle->fresh()?->status);
    }

    public function test_cancelling_a_draft_releases_the_reservation(): void
    {
        $vehicle = $this->vehicle(Vehicle::STATUS_RESERVED);
        $order = RentalOrder::query()->create([
            'customer_id' => $this->customer()->id, 'vehicle_id' => $vehicle->id,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-03',
        ]);

        $order->cancelOrder();

        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicle->fresh()?->status);
    }

    public function test_double_booking_is_blocked_for_overlapping_dates(): void
    {
        $customer = $this->customer();
        $vehicle = $this->vehicle();
        RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-05',
        ]);

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', '2026-08-03') // overlaps the existing booking
            ->set('end_date', '2026-08-07')
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasErrors('vehicle_id');

        // No second order was created.
        $this->assertSame(1, RentalOrder::query()->count());
    }

    public function test_non_overlapping_dates_are_allowed(): void
    {
        $customer = $this->customer();
        $vehicle = $this->vehicle();
        RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-05',
        ]);

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', '2026-08-06') // after the first booking ends
            ->set('end_date', '2026-08-08')
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, RentalOrder::query()->count());
    }

    public function test_editing_the_same_order_is_not_blocked_by_itself(): void
    {
        $customer = $this->customer();
        $vehicle = $this->vehicle();
        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-05',
        ]);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->set('end_date', '2026-08-06')
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();
    }
}

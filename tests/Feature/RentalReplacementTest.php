<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\ReplacementForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalReplacement;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * A car replacement is a swap on a live rental: the order moves onto the new car
 * for the rest of the agreement, the original goes to maintenance (breakdown) or
 * back to the fleet (customer request), and the agreed price is kept.
 */
final class RentalReplacementTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    /** A car with valid papers, available to book. */
    private function bookableCar(string $name, string $plate, string $status = Vehicle::STATUS_AVAILABLE): Vehicle
    {
        return Vehicle::query()->create([
            'name' => $name, 'plate_no' => $plate, 'color' => 'White', 'daily_rate' => 10,
            'status' => $status, 'odometer' => 1000,
            'registration_expiry' => Carbon::now()->addYear(),
            'insurance_expiry' => Carbon::now()->addYear(),
        ]);
    }

    /** An active rental with the car physically out on the road. */
    private function onRoadOrder(Vehicle $car): RentalOrder
    {
        return RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'Qassim', 'phone' => '39000000'])->id,
            'vehicle_id' => $car->id,
            'start_date' => Carbon::now()->subDay(), 'end_date' => Carbon::now()->addDays(3),
            'rate_type' => 'daily', 'rate' => 10, 'total' => 40,
            'state' => RentalOrder::STATE_ACTIVE, 'started_at' => Carbon::now()->subDay(),
            'handover_km' => 1000, 'handover_fuel' => 'full',
        ]);
    }

    public function test_a_breakdown_swap_moves_the_order_onto_the_new_car(): void
    {
        $original = $this->bookableCar('Eco Sport', '111', Vehicle::STATUS_RENTED);
        $replacement = $this->bookableCar('Expedition', '222');
        $order = $this->onRoadOrder($original);

        Livewire::test(ReplacementForm::class, ['order' => $order->id])
            ->set('reason_type', RentalReplacement::REASON_BREAKDOWN)
            ->set('replacement_vehicle_id', $replacement->id)
            ->set('original_return_km', '1500')
            ->set('replacement_handover_km', '2000')
            ->set('replacement_handover_fuel', 'half')
            ->call('save')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame($replacement->id, $order->vehicle_id);          // order now on the new car
        $this->assertSame(2000, $order->handover_km);                     // baseline moved to the new car
        $this->assertSame('half', $order->handover_fuel);
        $this->assertSame(40.0, $order->total);                           // price unchanged

        $this->assertSame(Vehicle::STATUS_MAINTENANCE, $original->fresh()?->status);
        $this->assertSame(Vehicle::STATUS_RENTED, $replacement->fresh()?->status);
        $this->assertSame(1500, $original->fresh()?->odometer);           // original odo advanced
    }

    public function test_a_customer_request_returns_the_original_to_the_fleet(): void
    {
        $original = $this->bookableCar('Eco Sport', '111', Vehicle::STATUS_RENTED);
        $replacement = $this->bookableCar('Expedition', '222');
        $order = $this->onRoadOrder($original);

        Livewire::test(ReplacementForm::class, ['order' => $order->id])
            ->set('reason_type', RentalReplacement::REASON_CUSTOMER)
            ->set('replacement_vehicle_id', $replacement->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(Vehicle::STATUS_AVAILABLE, $original->fresh()?->status); // not maintenance
        $this->assertSame(Vehicle::STATUS_RENTED, $replacement->fresh()?->status);
    }

    public function test_returning_to_the_original_car_hands_the_loaner_back_to_the_fleet(): void
    {
        // The customer is on a loaner; swapping back to their own car returns the
        // loaner to the fleet (not maintenance) and puts their car back on the road.
        $loaner = $this->bookableCar('Loaner', '111', Vehicle::STATUS_RENTED);
        $ownCar = $this->bookableCar('Own Car', '222');
        $order = $this->onRoadOrder($loaner);

        Livewire::test(ReplacementForm::class, ['order' => $order->id])
            ->set('reason_type', RentalReplacement::REASON_RETURN)
            ->set('replacement_vehicle_id', $ownCar->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($ownCar->id, $order->fresh()?->vehicle_id);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $loaner->fresh()?->status); // loaner freed, not maintenance
        $this->assertSame(Vehicle::STATUS_RENTED, $ownCar->fresh()?->status);
    }

    public function test_the_replacement_must_be_an_available_bookable_car(): void
    {
        $original = $this->bookableCar('Eco Sport', '111', Vehicle::STATUS_RENTED);
        $busy = $this->bookableCar('Busy', '222', Vehicle::STATUS_RENTED); // not available
        $order = $this->onRoadOrder($original);

        Livewire::test(ReplacementForm::class, ['order' => $order->id])
            ->set('reason_type', RentalReplacement::REASON_BREAKDOWN)
            ->set('replacement_vehicle_id', $busy->id)
            ->call('save')
            ->assertHasErrors('replacement_vehicle_id');

        $this->assertSame($original->id, $order->fresh()?->vehicle_id); // no swap happened
    }

    public function test_a_super_admin_can_swap_in_a_car_with_lapsed_papers(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));

        $original = $this->bookableCar('Eco Sport', '111', Vehicle::STATUS_RENTED);
        $noPapers = Vehicle::query()->create([   // available, but no registration/insurance
            'name' => 'Spare', 'plate_no' => '222', 'daily_rate' => 10, 'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        $order = $this->onRoadOrder($original);

        Livewire::test(ReplacementForm::class, ['order' => $order->id])
            ->set('reason_type', RentalReplacement::REASON_BREAKDOWN)
            ->set('replacement_vehicle_id', $noPapers->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($noPapers->id, $order->fresh()?->vehicle_id); // urgent override allowed
    }

    public function test_a_non_super_admin_cannot_swap_in_a_car_with_lapsed_papers(): void
    {
        // setUp acts as a plain admin (not super).
        $original = $this->bookableCar('Eco Sport', '111', Vehicle::STATUS_RENTED);
        $noPapers = Vehicle::query()->create([
            'name' => 'Spare', 'plate_no' => '222', 'daily_rate' => 10, 'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        $order = $this->onRoadOrder($original);

        Livewire::test(ReplacementForm::class, ['order' => $order->id])
            ->set('reason_type', RentalReplacement::REASON_BREAKDOWN)
            ->set('replacement_vehicle_id', $noPapers->id)
            ->call('save')
            ->assertHasErrors('replacement_vehicle_id');

        $this->assertSame($original->id, $order->fresh()?->vehicle_id); // blocked, no swap
    }

    public function test_a_replacement_cannot_be_started_without_an_on_road_order(): void
    {
        $car = $this->bookableCar('Eco Sport', '111');
        $draft = RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'Q'])->id,
            'vehicle_id' => $car->id,
            'start_date' => Carbon::now(), 'end_date' => Carbon::now()->addDay(),
            'rate_type' => 'daily', 'rate' => 10, 'state' => RentalOrder::STATE_DRAFT, // not started
        ]);

        Livewire::test(ReplacementForm::class, ['order' => $draft->id])
            ->assertSet('blocked', true)
            ->assertSee('Start a replacement from a rental');
    }

    public function test_closing_a_replacement_frees_the_original_car(): void
    {
        $original = $this->bookableCar('Eco Sport', '111', Vehicle::STATUS_RENTED);
        $replacement = $this->bookableCar('Expedition', '222');
        $order = $this->onRoadOrder($original);

        Livewire::test(ReplacementForm::class, ['order' => $order->id])
            ->set('reason_type', RentalReplacement::REASON_BREAKDOWN)
            ->set('replacement_vehicle_id', $replacement->id)
            ->call('save');

        $rep = RentalReplacement::query()->latest('id')->sole();
        $this->assertSame(Vehicle::STATUS_MAINTENANCE, $original->fresh()?->status);

        Livewire::test(ReplacementForm::class, ['id' => $rep->id])->call('close');

        $this->assertSame(RentalReplacement::STATUS_CLOSED, $rep->fresh()?->status);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $original->fresh()?->status); // back in the fleet
        $this->assertSame(Vehicle::STATUS_RENTED, $replacement->fresh()?->status); // still out with customer
    }
}

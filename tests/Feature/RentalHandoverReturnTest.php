<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Handover & return inspection: the desk records the car's condition (fuel, KM,
 * problems, damage, a cloud video link) when the car goes out and comes back,
 * and the KM read updates the vehicle's odometer.
 */
final class RentalHandoverReturnTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    /** @return array{0: RentalOrder, 1: Vehicle} */
    private function draftOrder(int $odometer = 10000): array
    {
        $vehicle = Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10, 'odometer' => $odometer]);
        $order = RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'Ali'])->id,
            'vehicle_id' => $vehicle->id,
            'start_date' => Carbon::now()->addDay(),
            'end_date' => Carbon::now()->addDays(3),
        ]);

        return [$order, $vehicle];
    }

    public function test_handover_records_condition_and_updates_the_odometer(): void
    {
        [$order, $vehicle] = $this->draftOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('startRental')               // opens the handover modal
            ->set('handover_km', '10250')
            ->set('handover_fuel', 'full')
            ->set('handover_notes', 'Check-engine light on')
            ->set('handover_video_url', 'https://drive.google.com/file/abc')
            ->call('confirmHandover')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(RentalOrder::STATE_ACTIVE, $order->state);
        $this->assertSame(10250, $order->handover_km);
        $this->assertSame('full', $order->handover_fuel);
        $this->assertSame('Check-engine light on', $order->handover_notes);
        $this->assertNotNull($order->started_at);

        $vehicle->refresh();
        $this->assertSame(Vehicle::STATUS_RENTED, $vehicle->status);
        $this->assertSame(10250, $vehicle->odometer); // odometer pushed forward
    }

    public function test_return_records_damage_and_updates_the_odometer(): void
    {
        [$order, $vehicle] = $this->draftOrder();
        $order->startRental(); // make it active

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('closeRental')               // opens the return modal
            ->set('return_km', '10500')
            ->set('return_fuel', 'half')
            ->set('has_damage', true)
            ->set('damage_notes', 'Scratch on the rear door')
            ->set('damage_video_url', 'https://photos.app.goo.gl/xyz')
            ->call('confirmReturn')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(RentalOrder::STATE_CLOSED, $order->state);
        $this->assertSame(10500, $order->return_km);
        $this->assertTrue($order->has_damage);
        $this->assertSame('Scratch on the rear door', $order->damage_notes);
        $this->assertNotNull($order->returned_at);

        $vehicle->refresh();
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicle->status);
        $this->assertSame(10500, $vehicle->odometer); // maintenance KM stays current
    }

    public function test_damage_notes_are_required_when_damage_is_ticked(): void
    {
        [$order] = $this->draftOrder();
        $order->startRental();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('closeRental')
            ->set('has_damage', true)
            ->set('damage_notes', '')
            ->call('confirmReturn')
            ->assertHasErrors(['damage_notes']);

        $this->assertSame(RentalOrder::STATE_ACTIVE, $order->fresh()?->state);
    }

    public function test_an_invalid_video_link_is_rejected(): void
    {
        [$order] = $this->draftOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('startRental')
            ->set('handover_video_url', 'not-a-real-link')
            ->call('confirmHandover')
            ->assertHasErrors(['handover_video_url']);

        $this->assertSame(RentalOrder::STATE_DRAFT, $order->fresh()?->state);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Livewire\MaintenanceForm;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Maintenance is a proper work-order workflow (fleet practice — Fleetio /
 * Oxmaint): raised → a MANAGER approves or declines it before any work / spend
 * → started (only when the car is free at the branch) → completed. Status moves
 * only through these guarded transitions, never a free dropdown.
 */
final class RentalMaintenanceGuardTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        // A fleet manager by default (admin) — may approve.
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function car(string $status): Vehicle
    {
        return Vehicle::query()->create(['name' => 'Eco Sport', 'daily_rate' => 10, 'status' => $status]);
    }

    private function record(Vehicle $car, string $status = RentalMaintenance::STATUS_PENDING): RentalMaintenance
    {
        return RentalMaintenance::query()->create([
            'vehicle_id' => $car->id, 'type' => 'service', 'date' => now(), 'cost' => 22, 'status' => $status,
        ]);
    }

    public function test_a_new_work_order_is_pending_and_leaves_the_car_alone(): void
    {
        $car = $this->car(Vehicle::STATUS_AVAILABLE);

        Livewire::test(MaintenanceForm::class)
            ->set('vehicle_id', $car->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('type', 'service')
            ->set('priority', 'high')
            ->set('cost', '22')
            ->call('save')
            ->assertHasNoErrors();

        $record = RentalMaintenance::query()->first();
        $this->assertSame(RentalMaintenance::STATUS_PENDING, $record?->status);
        $this->assertSame('high', $record?->priority);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $car->fresh()?->status); // untouched
    }

    public function test_the_full_workflow_approve_start_complete(): void
    {
        $manager = User::factory()->create(['is_admin' => true]);
        $this->actingAs($manager);
        $car = $this->car(Vehicle::STATUS_AVAILABLE);
        $record = $this->record($car);

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])
            ->call('approveMaintenance')->assertSet('status', RentalMaintenance::STATUS_APPROVED)
            ->call('startMaintenance')->assertSet('status', RentalMaintenance::STATUS_IN_PROGRESS)
            ->call('completeMaintenance')->assertSet('status', RentalMaintenance::STATUS_DONE);

        $record->refresh();
        $this->assertSame($manager->id, $record->approved_by_user_id);
        $this->assertNotNull($record->approved_at);
        $this->assertNotNull($record->started_at);
        $this->assertNotNull($record->completed_at);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $car->fresh()?->status); // freed again
    }

    public function test_a_non_manager_cannot_approve_a_work_order(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false, 'is_super_admin' => false]));
        $record = $this->record($this->car(Vehicle::STATUS_AVAILABLE));

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])
            ->call('approveMaintenance')
            ->assertForbidden();

        $this->assertSame(RentalMaintenance::STATUS_PENDING, $record->fresh()?->status);
    }

    public function test_a_pending_work_order_cannot_be_started_before_approval(): void
    {
        $car = $this->car(Vehicle::STATUS_AVAILABLE);
        $record = $this->record($car); // pending, not approved

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])->call('startMaintenance');

        $this->assertSame(RentalMaintenance::STATUS_PENDING, $record->fresh()?->status);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $car->fresh()?->status);
    }

    public function test_an_approved_order_cannot_start_while_the_car_is_rented(): void
    {
        $car = $this->car(Vehicle::STATUS_RENTED);
        $record = $this->record($car, RentalMaintenance::STATUS_APPROVED);

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])->call('startMaintenance');

        $this->assertSame(RentalMaintenance::STATUS_APPROVED, $record->fresh()?->status); // didn't start
        $this->assertSame(Vehicle::STATUS_RENTED, $car->fresh()?->status);
    }

    public function test_a_manager_can_decline_a_work_order(): void
    {
        $record = $this->record($this->car(Vehicle::STATUS_AVAILABLE));

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])->call('declineMaintenance');

        $this->assertSame(RentalMaintenance::STATUS_DECLINED, $record->fresh()?->status);
    }

    public function test_a_pending_work_order_can_be_cancelled(): void
    {
        $record = $this->record($this->car(Vehicle::STATUS_AVAILABLE));

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])->call('cancelMaintenance');

        $this->assertSame(RentalMaintenance::STATUS_CANCELLED, $record->fresh()?->status);
    }
}

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
 * Maintenance is a guarded workflow (Scheduled → Start → Complete / Cancel),
 * not a free status dropdown. Starting takes the car off the road and is
 * blocked unless the car is free at the branch — a rented/reserved car must be
 * released (e.g. a replacement) first.
 */
final class RentalMaintenanceGuardTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function car(string $status): Vehicle
    {
        return Vehicle::query()->create(['name' => 'Eco Sport', 'daily_rate' => 10, 'status' => $status]);
    }

    private function scheduledRecord(Vehicle $car): RentalMaintenance
    {
        return RentalMaintenance::query()->create([
            'vehicle_id' => $car->id, 'type' => 'service', 'date' => now(), 'cost' => 22,
            'status' => RentalMaintenance::STATUS_SCHEDULED,
        ]);
    }

    public function test_a_rented_car_cannot_be_started_into_maintenance(): void
    {
        $car = $this->car(Vehicle::STATUS_RENTED);
        $record = $this->scheduledRecord($car);

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])->call('startMaintenance');

        $this->assertSame(Vehicle::STATUS_RENTED, $car->fresh()?->status);                  // untouched
        $this->assertSame(RentalMaintenance::STATUS_SCHEDULED, $record->fresh()?->status);   // didn't start
    }

    public function test_a_reserved_car_cannot_be_started_into_maintenance(): void
    {
        $car = $this->car(Vehicle::STATUS_RESERVED);
        $record = $this->scheduledRecord($car);

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])->call('startMaintenance');

        $this->assertSame(Vehicle::STATUS_RESERVED, $car->fresh()?->status);
        $this->assertSame(RentalMaintenance::STATUS_SCHEDULED, $record->fresh()?->status);
    }

    public function test_an_available_car_starts_into_maintenance(): void
    {
        $car = $this->car(Vehicle::STATUS_AVAILABLE);
        $record = $this->scheduledRecord($car);

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])->call('startMaintenance');

        $this->assertSame(Vehicle::STATUS_MAINTENANCE, $car->fresh()?->status);
        $this->assertSame(RentalMaintenance::STATUS_IN_PROGRESS, $record->fresh()?->status);
    }

    public function test_completing_maintenance_frees_the_car(): void
    {
        $car = $this->car(Vehicle::STATUS_MAINTENANCE);
        $record = RentalMaintenance::query()->create([
            'vehicle_id' => $car->id, 'type' => 'service', 'date' => now(),
            'status' => RentalMaintenance::STATUS_IN_PROGRESS,
        ]);

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])->call('completeMaintenance');

        $this->assertSame(Vehicle::STATUS_AVAILABLE, $car->fresh()?->status);
        $this->assertSame(RentalMaintenance::STATUS_DONE, $record->fresh()?->status);
    }

    public function test_cancelling_a_scheduled_record_leaves_the_car_alone(): void
    {
        $car = $this->car(Vehicle::STATUS_AVAILABLE);
        $record = $this->scheduledRecord($car);

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])->call('cancelMaintenance');

        $this->assertSame(RentalMaintenance::STATUS_CANCELLED, $record->fresh()?->status);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $car->fresh()?->status);
    }

    public function test_scheduling_for_a_rented_car_is_allowed(): void
    {
        // Planning a future service while the car is out is fine — a new record
        // is born Scheduled and doesn't touch the car's availability.
        $car = $this->car(Vehicle::STATUS_RENTED);

        Livewire::test(MaintenanceForm::class)
            ->set('vehicle_id', $car->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('type', 'service')
            ->set('cost', '22')
            ->call('save')
            ->assertHasNoErrors();

        $record = RentalMaintenance::query()->first();
        $this->assertSame(RentalMaintenance::STATUS_SCHEDULED, $record?->status);
        $this->assertSame(Vehicle::STATUS_RENTED, $car->fresh()?->status); // still out
    }
}

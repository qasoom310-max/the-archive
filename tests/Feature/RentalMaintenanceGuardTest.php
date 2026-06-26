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
 * A rented-out (or reserved) car can't be sent straight into maintenance — it
 * must be freed (via a replacement) and back at the branch first. Only an
 * "in progress" record is blocked; scheduling ahead is allowed.
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

    public function test_a_rented_car_cannot_be_put_into_maintenance(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'daily_rate' => 10, 'status' => Vehicle::STATUS_RENTED]);

        Livewire::test(MaintenanceForm::class)
            ->set('vehicle_id', $car->id)
            ->set('type', 'service')
            ->set('cost', '22')
            ->set('status', RentalMaintenance::STATUS_IN_PROGRESS)
            ->call('save')
            ->assertHasErrors('vehicle_id');

        $this->assertSame(Vehicle::STATUS_RENTED, $car->fresh()?->status); // untouched
        $this->assertSame(0, RentalMaintenance::query()->count());          // nothing saved
    }

    public function test_a_reserved_car_cannot_be_put_into_maintenance(): void
    {
        $car = Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10, 'status' => Vehicle::STATUS_RESERVED]);

        Livewire::test(MaintenanceForm::class)
            ->set('vehicle_id', $car->id)
            ->set('status', RentalMaintenance::STATUS_IN_PROGRESS)
            ->call('save')
            ->assertHasErrors('vehicle_id');

        $this->assertSame(Vehicle::STATUS_RESERVED, $car->fresh()?->status);
    }

    public function test_an_available_car_goes_into_maintenance(): void
    {
        $car = Vehicle::query()->create(['name' => 'Camry', 'daily_rate' => 10, 'status' => Vehicle::STATUS_AVAILABLE]);

        Livewire::test(MaintenanceForm::class)
            ->set('vehicle_id', $car->id)
            ->set('type', 'service')
            ->set('cost', '22')
            ->set('status', RentalMaintenance::STATUS_IN_PROGRESS)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(Vehicle::STATUS_MAINTENANCE, $car->fresh()?->status);
        $this->assertSame(1, RentalMaintenance::query()->count());
    }

    public function test_scheduling_maintenance_for_a_rented_car_is_allowed(): void
    {
        // Planning a future service while the car is still out is fine — it
        // doesn't pull the car off the road (status stays as-is).
        $car = Vehicle::query()->create(['name' => 'Sunny', 'daily_rate' => 10, 'status' => Vehicle::STATUS_RENTED]);

        Livewire::test(MaintenanceForm::class)
            ->set('vehicle_id', $car->id)
            ->set('status', RentalMaintenance::STATUS_SCHEDULED)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(Vehicle::STATUS_RENTED, $car->fresh()?->status); // still out
        $this->assertSame(1, RentalMaintenance::query()->count());
    }

    public function test_editing_an_existing_in_progress_record_is_not_blocked(): void
    {
        // Car already under maintenance because of this record — re-saving it
        // (e.g. adding notes) must not trip the guard.
        $car = Vehicle::query()->create(['name' => 'Accent', 'daily_rate' => 10, 'status' => Vehicle::STATUS_MAINTENANCE]);
        $record = RentalMaintenance::query()->create([
            'vehicle_id' => $car->id, 'type' => 'service', 'cost' => 10,
            'date' => now(), 'status' => RentalMaintenance::STATUS_IN_PROGRESS,
        ]);

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])
            ->set('notes', 'Waiting on parts')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Waiting on parts', $record->fresh()?->notes);
    }
}

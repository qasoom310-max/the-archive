<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\VehicleForm;
use Modules\Rental\Models\RentalReplacement;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * A car parked in maintenance (e.g. by a breakdown swap) can be brought back to
 * the available fleet from its own page, which also closes the open replacement.
 */
final class RentalReturnToServiceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');

        // Bespoke rental screens are ACL-gated; these tests are about the role
        // rules on top of that, so put the user where a granted staff account is.
        $this->grantEveryone('rental.vehicle', 'rental.maintenance');
    }

    public function test_a_manager_returns_a_maintenance_car_to_service_and_closes_the_replacement(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'status' => Vehicle::STATUS_MAINTENANCE]);
        $replacement = RentalReplacement::query()->create([
            'original_vehicle_id' => $car->id,
            'date' => Carbon::now(),
            'reason_type' => RentalReplacement::REASON_BREAKDOWN,
            'status' => RentalReplacement::STATUS_ACTIVE,
        ]);

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->call('returnToService');

        $this->assertSame(Vehicle::STATUS_AVAILABLE, $car->fresh()?->status);
        $this->assertSame(RentalReplacement::STATUS_CLOSED, $replacement->fresh()?->status);
    }

    public function test_the_button_only_shows_for_a_car_in_maintenance(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $available = Vehicle::query()->create(['name' => 'Free', 'status' => Vehicle::STATUS_AVAILABLE]);
        Livewire::test(VehicleForm::class, ['id' => $available->id])
            ->assertDontSee('Return to service');

        $inShop = Vehicle::query()->create(['name' => 'In Shop', 'status' => Vehicle::STATUS_MAINTENANCE]);
        Livewire::test(VehicleForm::class, ['id' => $inShop->id])
            ->assertSee('Return to service');
    }

    public function test_a_non_manager_cannot_return_a_car_to_service(): void
    {
        // A plain staff user (not admin / super-admin / accountant manager).
        $this->actingAs(User::factory()->create());

        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'status' => Vehicle::STATUS_MAINTENANCE]);

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->call('returnToService')
            ->assertForbidden();

        $this->assertSame(Vehicle::STATUS_MAINTENANCE, $car->fresh()?->status);
    }
}

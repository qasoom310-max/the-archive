<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Support\CarImporter;
use Tests\TestCase;

/**
 * Cars rented in from outside are flagged on import and kept out of the dashboard
 * fleet KPIs (which count owned cars only), while still appearing in the fleet.
 */
final class RentalOutsideCarTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    public function test_import_flags_the_outside_vehicle_column(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cars') . '.csv';
        file_put_contents(
            $path,
            "Name,Reg.No,Year,Color,Type,Outside vehicle,Status\n" .
            "NISSAN PATROL,244139,2021,WHITE,Luxury,,Available\n" .       // owned
            "Accent ACCENT,657760,2021,WHITE,Small,Yes,Rented Out\n"      // outside
        );

        app(CarImporter::class)->import($path);

        $this->assertFalse(Vehicle::query()->where('plate_no', '244139')->sole()->is_outside);
        $this->assertTrue(Vehicle::query()->where('plate_no', '657760')->sole()->is_outside);
    }

    public function test_dashboard_fleet_counts_owned_cars_only(): void
    {
        Vehicle::query()->create(['name' => 'Owned A', 'status' => Vehicle::STATUS_AVAILABLE]);
        Vehicle::query()->create(['name' => 'Owned B', 'status' => Vehicle::STATUS_RENTED]);
        Vehicle::query()->create(['name' => 'Outside 1', 'status' => Vehicle::STATUS_AVAILABLE, 'is_outside' => true]);
        Vehicle::query()->create(['name' => 'Outside 2', 'status' => Vehicle::STATUS_AVAILABLE, 'is_outside' => true]);

        Livewire::test(RentalHome::class)
            ->assertViewHas('total', 2)          // owned only
            ->assertViewHas('available', 1)      // owned available
            ->assertViewHas('rented', 1)
            ->assertViewHas('outsideCount', 2);  // rented-in, shown as context
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Features;
use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Livewire\Views\FormView;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Models\Branch;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

final class RentalModuleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingManager::class)->flush();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function install(): void
    {
        app(ModuleManager::class)->install('rental');
    }

    public function test_install_creates_schema_and_registers_masters(): void
    {
        $this->install();

        $this->assertSame(ModuleState::Installed, IrModule::query()->where('name', 'rental')->sole()->state);

        foreach (['rental_branches', 'rental_customers', 'rental_vehicles', 'rental_drivers'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing {$table}");
        }

        $this->assertEqualsCanonicalizing(
            ['rental.branch', 'rental.customer', 'rental.vehicle', 'rental.driver'],
            IrModel::query()->where('module', 'rental')->pluck('model')->all(),
        );
    }

    public function test_dashboard_renders_fleet_kpis_and_branches(): void
    {
        $this->install();

        $salihiya = Branch::query()->create(['name' => 'Salihiya']);
        Vehicle::query()->create(['name' => 'Yaris', 'branch_id' => $salihiya->id, 'status' => Vehicle::STATUS_AVAILABLE, 'daily_rate' => 10]);
        Vehicle::query()->create(['name' => 'Camry', 'branch_id' => $salihiya->id, 'status' => Vehicle::STATUS_RENTED, 'daily_rate' => 15]);
        Vehicle::query()->create(['name' => 'Hiace', 'status' => Vehicle::STATUS_MAINTENANCE]);

        Livewire::test(RentalHome::class)
            ->assertOk()
            ->assertSee('Fleet status')
            ->assertSee('Availability by branch')
            ->assertSee('Salihiya');
    }

    public function test_vehicle_is_created_through_the_engine_form(): void
    {
        $this->install();
        $branch = Branch::query()->create(['name' => 'Juffair']);

        Livewire::test(FormView::class, ['model' => Vehicle::class, 'modelKey' => 'rental.vehicle'])
            ->assertSet('form.status', Vehicle::STATUS_AVAILABLE) // model default
            ->set('form.name', 'Toyota Yaris 2023')
            ->set('form.daily_rate', 12.5)
            ->set('form.deposit', 50)
            ->set('form.branch_id', $branch->id)
            ->call('save')
            ->assertHasNoErrors();

        $vehicle = Vehicle::query()->where('name', 'Toyota Yaris 2023')->sole();
        $this->assertEqualsWithDelta(12.5, $vehicle->daily_rate, 0.001);
        $this->assertEqualsWithDelta(50.0, $vehicle->deposit, 0.001);
        $this->assertSame($branch->id, $vehicle->branch_id);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicle->status);
    }

    public function test_rental_app_is_gated_by_the_bookings_feature(): void
    {
        $this->seed(SettingSeeder::class);

        // A retail/café business hides the rental app…
        Setting::set('company.business_type', 'retail');
        $this->assertFalse(Features::moduleAllowed('rental'));

        // …a rental business shows it.
        Setting::set('company.business_type', 'rental');
        $this->assertTrue(Features::moduleAllowed('rental'));
    }
}

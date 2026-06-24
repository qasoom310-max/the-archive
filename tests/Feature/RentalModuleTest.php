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
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Livewire\Orders;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Models\Branch;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalQuotation;
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
            ['rental.branch', 'rental.customer', 'rental.vehicle', 'rental.driver', 'rental.order', 'rental.quotation'],
            IrModel::query()->where('module', 'rental')->pluck('model')->all(),
        );
        $this->assertTrue(Schema::hasTable('rental_orders'));
        $this->assertTrue(Schema::hasTable('rental_quotations'));
    }

    public function test_quotation_converts_to_a_draft_order(): void
    {
        $this->install();
        $customer = RentalCustomer::query()->create(['name' => 'Sara']);
        $vehicle = Vehicle::query()->create(['name' => 'Sunny', 'daily_rate' => 8, 'deposit' => 40]);

        $quote = RentalQuotation::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'start_date' => '2026-07-01', 'end_date' => '2026-07-03',
            'rate_type' => 'daily', 'rate' => 8, 'deposit' => 40,
        ]);
        $quote->recalcTotals();
        $quote->save();

        $order = $quote->convertToOrder();

        $this->assertSame(RentalQuotation::STATUS_CONVERTED, $quote->fresh()->status);
        $this->assertSame($order->id, $quote->fresh()->order_id);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($vehicle->id, $order->vehicle_id);
        $this->assertEqualsWithDelta(16.0, $order->total, 0.001); // 8 × 2 days
        $this->assertSame(RentalOrder::STATE_DRAFT, $order->state);

        // Converting again is idempotent — same order, no duplicate.
        $again = $quote->fresh()->convertToOrder();
        $this->assertSame($order->id, $again->id);
        $this->assertSame(1, RentalOrder::query()->count());
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

    public function test_order_form_creates_a_draft_with_computed_totals(): void
    {
        $this->install();
        $customer = RentalCustomer::query()->create(['name' => 'Ali']);
        $branch = Branch::query()->create(['name' => 'Salihiya']);
        $vehicle = Vehicle::query()->create([
            'name' => 'Yaris', 'branch_id' => $branch->id,
            'daily_rate' => 10, 'deposit' => 50, 'status' => Vehicle::STATUS_AVAILABLE,
        ]);

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)   // auto-fills rate (10) + deposit (50)
            ->assertSet('rate', '10')
            ->assertSet('deposit', '50')
            ->set('start_date', '2026-07-01')
            ->set('end_date', '2026-07-04')     // 3 days
            ->call('save')
            ->assertHasNoErrors();

        $order = RentalOrder::query()->sole();
        $this->assertSame(RentalOrder::STATE_DRAFT, $order->state);
        $this->assertSame(3, $order->days);
        $this->assertEqualsWithDelta(30.0, $order->total, 0.001); // 10 × 3
        $this->assertEqualsWithDelta(50.0, $order->deposit, 0.001);
        $this->assertNotNull($order->reference);
    }

    public function test_order_lifecycle_keeps_vehicle_status_in_sync(): void
    {
        $this->install();
        $vehicle = Vehicle::query()->create(['name' => 'Camry', 'daily_rate' => 12, 'status' => Vehicle::STATUS_AVAILABLE]);
        $order = RentalOrder::query()->create([
            'vehicle_id' => $vehicle->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-03',
            'rate_type' => 'daily', 'rate' => 12,
        ]);

        $order->startRental();
        $this->assertSame(RentalOrder::STATE_ACTIVE, $order->fresh()->state);
        $this->assertSame(Vehicle::STATUS_RENTED, $vehicle->fresh()->status);

        $order->closeRental();
        $this->assertSame(RentalOrder::STATE_CLOSED, $order->fresh()->state);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicle->fresh()->status);
    }

    public function test_orders_list_filters_by_state(): void
    {
        $this->install();
        $active = RentalOrder::query()->create(['state' => RentalOrder::STATE_ACTIVE, 'start_date' => '2026-07-01', 'end_date' => '2026-07-02']);
        $draft = RentalOrder::query()->create(['state' => RentalOrder::STATE_DRAFT, 'start_date' => '2026-07-01', 'end_date' => '2026-07-02']);

        Livewire::test(Orders::class)
            ->set('tab', 'active')
            ->assertSee($active->fresh()->reference)
            ->assertDontSee($draft->fresh()->reference);
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

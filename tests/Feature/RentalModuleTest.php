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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Rental\Http\Controllers\RentalReportExportController;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Livewire\Orders;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Livewire\Reports;
use Modules\Rental\Models\Branch;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Models\RentalReplacement;
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
            ['rental.branch', 'rental.customer', 'rental.vehicle', 'rental.driver', 'rental.order', 'rental.quotation', 'rental.invoice', 'rental.receipt', 'rental.replacement', 'rental.maintenance'],
            IrModel::query()->where('module', 'rental')->pluck('model')->all(),
        );
        foreach (['rental_orders', 'rental_quotations', 'rental_invoices', 'rental_receipts', 'rental_replacements', 'rental_maintenance'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing {$table}");
        }
    }

    public function test_replacement_swaps_vehicle_availability(): void
    {
        $this->install();
        $original = Vehicle::query()->create(['name' => 'Civic', 'status' => Vehicle::STATUS_RENTED]);
        $spare = Vehicle::query()->create(['name' => 'Corolla', 'status' => Vehicle::STATUS_AVAILABLE]);

        $replacement = RentalReplacement::query()->create([
            'original_vehicle_id' => $original->id,
            'replacement_vehicle_id' => $spare->id,
            'date' => '2026-07-02', 'reason_type' => RentalReplacement::REASON_BREAKDOWN,
        ]);
        $replacement->activate();

        // The spare goes out; a breakdown sends the original to maintenance.
        $this->assertSame(Vehicle::STATUS_RENTED, $spare->fresh()->status);
        $this->assertSame(Vehicle::STATUS_MAINTENANCE, $original->fresh()->status);

        // Closing returns the original to the fleet; the spare stays out with the customer.
        $replacement->close();
        $this->assertSame(RentalReplacement::STATUS_CLOSED, $replacement->fresh()->status);
        $this->assertSame(Vehicle::STATUS_RENTED, $spare->fresh()->status);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $original->fresh()->status);
    }

    public function test_maintenance_status_reflects_on_the_vehicle(): void
    {
        $this->install();
        $vehicle = Vehicle::query()->create(['name' => 'Sonata', 'status' => Vehicle::STATUS_AVAILABLE]);

        $record = RentalMaintenance::query()->create([
            'vehicle_id' => $vehicle->id, 'date' => '2026-07-02',
            'type' => 'repair', 'cost' => 35, 'status' => RentalMaintenance::STATUS_IN_PROGRESS,
        ]);
        $record->syncVehicleStatus();
        $this->assertSame(Vehicle::STATUS_MAINTENANCE, $vehicle->fresh()->status);

        $record->status = RentalMaintenance::STATUS_DONE;
        $record->save();
        $record->syncVehicleStatus();
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicle->fresh()->status);
    }

    public function test_invoice_from_order_settles_via_receipts(): void
    {
        $this->install();
        $customer = RentalCustomer::query()->create(['name' => 'Mona']);
        $vehicle = Vehicle::query()->create(['name' => 'Patrol', 'daily_rate' => 25]);
        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'start_date' => '2026-07-01', 'end_date' => '2026-07-05', // 4 days
            'rate_type' => 'daily', 'rate' => 25, 'vat_rate' => 0, // VAT-free, focus on settlement
        ]);
        $order->recalcTotals();
        $order->save();

        $invoice = $order->createInvoice();
        $this->assertEqualsWithDelta(100.0, $invoice->total, 0.001); // 25 × 4
        $this->assertSame(RentalInvoice::STATUS_UNPAID, $invoice->status);

        // Creating an invoice again is idempotent (same invoice).
        $this->assertSame($invoice->id, $order->createInvoice()->id);

        // Partial payment → partial status.
        RentalReceipt::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id,
            'date' => '2026-07-01', 'amount' => 40, 'method' => 'cash',
        ]);
        $invoice->refresh();
        $this->assertSame(RentalInvoice::STATUS_PARTIAL, $invoice->status);
        $this->assertEqualsWithDelta(40.0, $invoice->amount_paid, 0.001);
        $this->assertEqualsWithDelta(60.0, $invoice->balance(), 0.001);

        // Settle the balance → paid, and the order is flagged paid.
        RentalReceipt::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id,
            'date' => '2026-07-02', 'amount' => 60, 'method' => 'benefit',
        ]);
        $invoice->refresh();
        $this->assertSame(RentalInvoice::STATUS_PAID, $invoice->status);
        $this->assertSame(RentalOrder::PAYMENT_PAID, $order->fresh()->payment_status);
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
        // 8 × 2 days = 16, + 10% VAT applied on the converted order = 17.6
        $this->assertEqualsWithDelta(17.6, $order->total, 0.001);
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

    public function test_vehicle_display_name_includes_plate_and_colour(): void
    {
        $this->install();
        $full = Vehicle::query()->create(['name' => 'Eco Sport', 'plate_no' => '123456', 'color' => 'White', 'daily_rate' => 10]);
        $this->assertSame('Eco Sport · 123456 · White', $full->displayName());

        // Missing parts are skipped, so a bare vehicle just shows its name.
        $bare = Vehicle::query()->create(['name' => 'Hiace', 'daily_rate' => 10]);
        $this->assertSame('Hiace', $bare->displayName());
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
        $this->assertEqualsWithDelta(30.0, $order->subtotal, 0.001); // 10 × 3 (pre-VAT amount)
        $this->assertEqualsWithDelta(33.0, $order->total, 0.001);    // + 10% VAT
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

    public function test_reports_aggregate_orders_by_vehicle_and_customer(): void
    {
        $this->install();
        $customer = RentalCustomer::query()->create(['name' => 'Reem']);
        $vehicle = Vehicle::query()->create(['name' => 'Accent', 'daily_rate' => 10]);
        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'start_date' => '2026-06-10', 'end_date' => '2026-06-12',
            'rate_type' => 'daily', 'rate' => 10,
        ]);
        $order->recalcTotals();
        $order->save();

        Livewire::test(Reports::class)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-30')
            ->assertOk()
            ->assertSee('Rental report')
            ->set('tab', 'orders')->assertSee($order->fresh()->reference)
            ->set('tab', 'vehicles')->assertSee('Accent')
            ->set('tab', 'customers')->assertSee('Reem');
    }

    public function test_orders_csv_export_streams_rows(): void
    {
        $this->install();
        $vehicle = Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10]);
        $order = RentalOrder::query()->create([
            'vehicle_id' => $vehicle->id, 'start_date' => '2026-06-10', 'end_date' => '2026-06-12',
            'rate_type' => 'daily', 'rate' => 10,
        ]);
        $order->recalcTotals();
        $order->save();

        // Module routes aren't mounted in the test harness — invoke directly.
        $response = (new RentalReportExportController())(
            Request::create('/x', 'GET', ['from' => '2026-06-01', 'to' => '2026-06-30']),
        );

        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString((string) $order->fresh()->reference, $csv);
        $this->assertStringContainsString('Yaris', $csv);
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

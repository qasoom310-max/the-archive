<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Livewire\InvoiceForm;
use Modules\Rental\Livewire\MaintenanceForm;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Livewire\QuotationForm;
use Modules\Rental\Models\Branch;
use Modules\Rental\Models\Driver;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Same fix as LimoFormInactiveRelationTest, for the Rental side: opening an
 * existing order/quotation/invoice/maintenance record whose customer,
 * vehicle, driver or branch was later deactivated must still show it, not a
 * blank "— Select —".
 */
final class RentalFormInactiveRelationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    public function test_invoice_form_keeps_a_deactivated_customer_visible(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Retired Co', 'active' => false]);
        $invoice = RentalInvoice::query()->create(['customer_id' => $customer->id, 'total' => 45]);

        Livewire::test(InvoiceForm::class, ['id' => $invoice->id])
            ->assertSet('customer_id', $customer->id)
            ->assertViewHas('customers', fn ($customers) => $customers->contains('id', $customer->id));
    }

    public function test_order_form_keeps_a_deactivated_customer_driver_and_branch_visible(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Retired Co', 'active' => false]);
        $vehicle = Vehicle::query()->create(['name' => 'Taurus', 'make' => 'Ford', 'model' => 'Taurus', 'plate_no' => '111629', 'daily_rate' => 33]);
        $driver = Driver::query()->create(['name' => 'Old Driver', 'active' => false]);
        $branch = Branch::query()->create(['name' => 'Closed Branch', 'active' => false]);

        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id, 'branch_id' => $branch->id,
            'start_date' => now(), 'end_date' => now()->addDay(),
            'rate_type' => 'daily', 'rate' => 33,
        ]);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->assertViewHas('customers', fn ($c) => $c->contains('id', $customer->id))
            ->assertViewHas('drivers', fn ($c) => $c->contains('id', $driver->id))
            ->assertViewHas('branches', fn ($c) => $c->contains('id', $branch->id));
    }

    public function test_quotation_form_keeps_every_deactivated_relation_visible(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Retired Co', 'active' => false]);
        $vehicle = Vehicle::query()->create(['name' => 'Taurus', 'make' => 'Ford', 'model' => 'Taurus', 'plate_no' => '111629', 'daily_rate' => 33, 'active' => false]);
        $driver = Driver::query()->create(['name' => 'Old Driver', 'active' => false]);
        $branch = Branch::query()->create(['name' => 'Closed Branch', 'active' => false]);

        $quote = RentalQuotation::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id, 'branch_id' => $branch->id,
            'total' => 100,
        ]);

        Livewire::test(QuotationForm::class, ['id' => $quote->id])
            ->assertViewHas('customers', fn ($c) => $c->contains('id', $customer->id))
            ->assertViewHas('vehicles', fn ($c) => $c->contains('id', $vehicle->id))
            ->assertViewHas('drivers', fn ($c) => $c->contains('id', $driver->id))
            ->assertViewHas('branches', fn ($c) => $c->contains('id', $branch->id));
    }

    public function test_maintenance_form_keeps_a_deactivated_vehicle_visible(): void
    {
        $vehicle = Vehicle::query()->create(['name' => 'Taurus', 'make' => 'Ford', 'model' => 'Taurus', 'plate_no' => '111629', 'daily_rate' => 33, 'active' => false]);
        $record = RentalMaintenance::query()->create([
            'vehicle_id' => $vehicle->id, 'date' => '2026-03-15', 'type' => 'service', 'cost' => 200, 'status' => 'done',
        ]);

        Livewire::test(MaintenanceForm::class, ['id' => $record->id])
            ->assertViewHas('vehicles', fn ($c) => $c->contains('id', $vehicle->id));
    }
}

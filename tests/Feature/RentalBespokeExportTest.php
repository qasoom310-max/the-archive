<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Modules\Rental\Http\Controllers\RentalInvoiceExportController;
use Modules\Rental\Http\Controllers\RentalMaintenanceExportController;
use Modules\Rental\Http\Controllers\RentalOrderExportController;
use Modules\Rental\Http\Controllers\RentalQuotationExportController;
use Modules\Rental\Http\Controllers\RentalReceiptExportController;
use Modules\Rental\Http\Controllers\RentalReplacementExportController;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Models\RentalReplacement;
use Modules\Rental\Models\Vehicle;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Copy/CSV/Excel/PDF/Print on the six Rental screens that are hand-written
 * lists, not the generic engine table — Invoice, Receipt, Maintenance,
 * Quotation, Replacement, Order. Each screen's own Rows service is the SAME
 * shape the Limousine booking queue proved out, sized down; the CSV/Excel/
 * PDF/Print mechanics themselves are shared (TabularRenderer), so this suite
 * checks the wiring per screen rather than re-proving the renderer.
 *
 * Module HTTP routes only mount on the boot AFTER install (the same engine
 * gap every other Rental export/import test works around), so the
 * controllers are invoked directly rather than through the router.
 */
final class RentalBespokeExportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    /**
     * Capture a StreamedResponse's body — the TestResponse::streamedContent()
     * helper only exists on a real HTTP round trip, not a direct controller call.
     */
    private function streamed(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function customer(): RentalCustomer
    {
        return RentalCustomer::query()->create(['name' => 'Qassim', 'phone' => '39000001']);
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::query()->create(['name' => 'Eco Sport', 'daily_rate' => 10]);
    }

    // --- Invoice -----------------------------------------------------

    public function test_invoice_csv_carries_the_row(): void
    {
        RentalInvoice::query()->create(['customer_id' => $this->customer()->id, 'total' => 45]);

        $response = app(RentalInvoiceExportController::class)->csv(Request::create('/x', 'GET'));
        $this->assertStringContainsString('Qassim', $this->streamed($response));
    }

    public function test_invoice_export_only_the_active_tab(): void
    {
        RentalInvoice::query()->create(['customer_id' => $this->customer()->id, 'total' => 45, 'status' => RentalInvoice::STATUS_PAID]);
        RentalInvoice::query()->create(['customer_id' => $this->customer()->id, 'total' => 20, 'status' => RentalInvoice::STATUS_UNPAID]);

        $response = app(RentalInvoiceExportController::class)->csv(Request::create('/x', 'GET', ['tab' => 'paid']));
        $body = $this->streamed($response);
        $this->assertStringContainsString('45.00', $body);
        $this->assertStringNotContainsString('20.00', $body);
    }

    public function test_invoice_export_is_read_gated(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(AuthorizationException::class);
        app(RentalInvoiceExportController::class)->csv(Request::create('/x', 'GET'));
    }

    public function test_invoice_csv_narrows_to_the_ticked_rows(): void
    {
        $customer = $this->customer();
        $a = RentalInvoice::query()->create(['customer_id' => $customer->id, 'total' => 100]);
        RentalInvoice::query()->create(['customer_id' => $customer->id, 'total' => 250]);

        $body = $this->streamed(app(RentalInvoiceExportController::class)->csv(Request::create('/x', 'GET', ['ids' => (string) $a->id])));
        $this->assertStringContainsString('100.00', $body);
        $this->assertStringNotContainsString('250.00', $body);
    }

    public function test_the_invoice_header_box_ticks_the_page_and_the_download_links_carry_the_ids(): void
    {
        $customer = $this->customer();
        $a = RentalInvoice::query()->create(['customer_id' => $customer->id, 'total' => 100]);
        $b = RentalInvoice::query()->create(['customer_id' => $customer->id, 'total' => 250]);

        \Livewire\Livewire::test(\Modules\Rental\Livewire\Invoices::class)
            ->set('selectPage', true)
            ->assertSet('selected', [$b->id, $a->id])
            ->assertSee('ids=' . $b->id . '%2C' . $a->id, false)
            ->call('clearSelection')
            ->assertSet('selected', [])
            ->assertDontSee('ids=' . $b->id, false); // link back to the whole tab
    }

    // --- Receipt -----------------------------------------------------

    public function test_receipt_csv_and_search_ride_along(): void
    {
        $mine = $this->customer();
        RentalReceipt::query()->create(['customer_id' => $mine->id, 'amount' => 30]);
        RentalReceipt::query()->create(['customer_id' => RentalCustomer::query()->create(['name' => 'Other'])->id, 'amount' => 15]);

        $response = app(RentalReceiptExportController::class)->csv(Request::create('/x', 'GET', ['q' => 'Qassim']));
        $body = $this->streamed($response);
        $this->assertStringContainsString('Qassim', $body);
        $this->assertStringNotContainsString('Other', $body);
    }

    public function test_receipt_downloads_narrow_to_the_ticked_rows(): void
    {
        $customer = $this->customer();
        $a = RentalReceipt::query()->create(['customer_id' => $customer->id, 'amount' => 30]);
        RentalReceipt::query()->create(['customer_id' => $customer->id, 'amount' => 250]);

        $body = $this->streamed(app(RentalReceiptExportController::class)->csv(Request::create('/x', 'GET', ['ids' => (string) $a->id])));
        $this->assertStringContainsString('30.00', $body);
        $this->assertStringNotContainsString('250.00', $body);
    }

    public function test_ticking_nothing_still_downloads_the_whole_receipt_list(): void
    {
        // The buttons must never change meaning underfoot.
        $customer = $this->customer();
        RentalReceipt::query()->create(['customer_id' => $customer->id, 'amount' => 30]);
        RentalReceipt::query()->create(['customer_id' => $customer->id, 'amount' => 250]);

        $body = $this->streamed(app(RentalReceiptExportController::class)->csv(Request::create('/x', 'GET', ['ids' => ''])));
        $this->assertStringContainsString('30.00', $body);
        $this->assertStringContainsString('250.00', $body);
    }

    public function test_the_receipt_header_box_ticks_the_page_and_the_links_carry_the_ids(): void
    {
        $customer = $this->customer();
        $a = RentalReceipt::query()->create(['customer_id' => $customer->id, 'amount' => 30]);
        $b = RentalReceipt::query()->create(['customer_id' => $customer->id, 'amount' => 250]);

        \Livewire\Livewire::test(\Modules\Rental\Livewire\Receipts::class)
            ->set('selectPage', true)
            ->assertSet('selected', [$b->id, $a->id])
            ->assertSee('ids=' . $b->id . '%2C' . $a->id, false)
            ->call('clearSelection')
            ->assertSet('selected', [])
            ->assertDontSee('ids=' . $b->id, false); // link back to the whole list
    }

    public function test_searching_drops_a_tick_made_against_the_old_search(): void
    {
        $mine = $this->customer();
        $a = RentalReceipt::query()->create(['customer_id' => $mine->id, 'amount' => 30]);
        RentalReceipt::query()->create(['customer_id' => RentalCustomer::query()->create(['name' => 'Other'])->id, 'amount' => 15]);

        \Livewire\Livewire::test(\Modules\Rental\Livewire\Receipts::class)
            ->set('selected', [$a->id])
            ->set('search', 'Other')
            ->assertSet('selected', []);
    }

    // --- Maintenance -----------------------------------------------------

    public function test_maintenance_pdf_and_print_render(): void
    {
        RentalMaintenance::query()->create(['vehicle_id' => $this->vehicle()->id, 'cost' => 12]);

        $this->assertSame(200, app(RentalMaintenanceExportController::class)->pdf(Request::create('/x', 'GET'))->getStatusCode());
        app(RentalMaintenanceExportController::class)->print(Request::create('/x', 'GET'));
        $this->addToAssertionCount(1); // no exception = the view rendered
    }

    // --- Quotation -----------------------------------------------------

    public function test_quotation_excel_downloads(): void
    {
        RentalQuotation::query()->create(['customer_id' => $this->customer()->id, 'vehicle_id' => $this->vehicle()->id, 'total' => 100]);

        $response = app(RentalQuotationExportController::class)->excel(Request::create('/x', 'GET'));
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
    }

    public function test_quotation_csv_narrows_to_the_ticked_rows(): void
    {
        $vehicle = $this->vehicle();
        $a = RentalQuotation::query()->create(['customer_id' => $this->customer()->id, 'vehicle_id' => $vehicle->id, 'total' => 100]);
        RentalQuotation::query()->create(['customer_id' => $this->customer()->id, 'vehicle_id' => $vehicle->id, 'total' => 250]);

        $body = $this->streamed(app(RentalQuotationExportController::class)->csv(Request::create('/x', 'GET', ['ids' => (string) $a->id])));
        $this->assertStringContainsString('100.00', $body);
        $this->assertStringNotContainsString('250.00', $body);
    }

    public function test_the_header_box_ticks_the_page_and_the_download_links_carry_the_ids(): void
    {
        $vehicle = $this->vehicle();
        $a = RentalQuotation::query()->create(['customer_id' => $this->customer()->id, 'vehicle_id' => $vehicle->id, 'total' => 100]);
        $b = RentalQuotation::query()->create(['customer_id' => $this->customer()->id, 'vehicle_id' => $vehicle->id, 'total' => 250]);

        \Livewire\Livewire::test(\Modules\Rental\Livewire\Quotations::class)
            ->set('selectPage', true)
            ->assertSet('selected', [$b->id, $a->id])
            ->assertSee('ids=' . $b->id . '%2C' . $a->id, false)
            ->call('clearSelection')
            ->assertSet('selected', [])
            ->assertDontSee('ids=' . $b->id, false); // link back to the whole tab
    }

    // --- Replacement -----------------------------------------------------

    public function test_replacement_export_names_both_cars(): void
    {
        $original = $this->vehicle();
        $replacement = Vehicle::query()->create(['name' => 'Sedan Plus', 'daily_rate' => 12]);
        RentalReplacement::query()->create([
            'customer_id' => $this->customer()->id,
            'original_vehicle_id' => $original->id,
            'replacement_vehicle_id' => $replacement->id,
        ]);

        $response = app(RentalReplacementExportController::class)->csv(Request::create('/x', 'GET'));
        $body = $this->streamed($response);
        $this->assertStringContainsString('Eco Sport', $body);
        $this->assertStringContainsString('Sedan Plus', $body);
    }

    // --- Order -----------------------------------------------------

    public function test_order_export_honours_tab_dates_and_search_together(): void
    {
        $customer = $this->customer();
        $vehicle = $this->vehicle();

        RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'order_date' => '2026-06-01', 'start_date' => '2026-06-01', 'end_date' => '2026-06-02',
            'rate_type' => 'daily', 'rate' => 10, 'state' => RentalOrder::STATE_ACTIVE,
        ]);
        // Outside the date window — must not appear.
        RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'order_date' => '2026-07-01', 'start_date' => '2026-07-01', 'end_date' => '2026-07-02',
            'rate_type' => 'daily', 'rate' => 10, 'state' => RentalOrder::STATE_ACTIVE,
        ]);

        $response = app(RentalOrderExportController::class)->csv(Request::create('/x', 'GET', [
            'tab' => 'active', 'from' => '2026-06-01', 'to' => '2026-06-30', 'q' => 'Qassim',
        ]));
        $body = $this->streamed($response);
        $this->assertStringContainsString('01-Jun-2026', $body);
        $this->assertStringNotContainsString('01-Jul-2026', $body);
    }

    public function test_order_export_is_read_gated(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(AuthorizationException::class);
        app(RentalOrderExportController::class)->csv(Request::create('/x', 'GET'));
    }
}

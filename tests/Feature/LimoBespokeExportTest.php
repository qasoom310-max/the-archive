<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Limousine\Http\Controllers\LimoInvoiceExportController;
use Modules\Limousine\Http\Controllers\LimoPettyAdvanceExportController;
use Modules\Limousine\Http\Controllers\LimoQuotationExportController;
use Modules\Limousine\Http\Controllers\LimoReceiptExportController;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\BookingPayments;
use Modules\Limousine\Services\PettyCash;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Copy/CSV/Excel/PDF/Print on the four Limousine screens that are hand-written
 * lists, not the generic engine table — Invoice, Receipt, Quotation, Petty
 * Cash. Each screen's own Rows service is the same shape as
 * {@see \Modules\Limousine\Services\LimoQueueRows}; the CSV/Excel/PDF/Print
 * mechanics are shared (TabularRenderer), so this suite checks the wiring per
 * screen rather than re-proving the renderer.
 *
 * Module HTTP routes only mount on the boot AFTER install (the same engine
 * gap every other bespoke export test works around), so the controllers are
 * invoked directly rather than through the router.
 */
final class LimoBespokeExportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_accountant' => true]));
        app(ModuleManager::class)->install('limousine');
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

    private function customer(string $name = 'Dadabhai Travel'): LimoCustomer
    {
        return LimoCustomer::query()->create(['name' => $name, 'type' => 'company']);
    }

    /** A billed trip, with its invoice raised. */
    private function trip(LimoCustomer $customer, float $fare, string $issued): LimoBooking
    {
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => $fare]);
        $invoice = $booking->syncInvoice();
        $invoice->forceFill(['issue_date' => $issued])->save();

        return $booking;
    }

    // --- Invoice -----------------------------------------------------

    public function test_invoice_csv_honours_the_date_window(): void
    {
        $customer = $this->customer();
        $this->trip($customer, 400, '2026-06-10');
        $this->trip($customer, 100, '2026-07-10');

        $response = app(LimoInvoiceExportController::class)->csv(Request::create('/x', 'GET', [
            'from' => '2026-06-01', 'to' => '2026-06-30',
        ]));
        $body = $this->streamed($response);
        $this->assertStringContainsString('10-Jun-2026', $body);
        $this->assertStringNotContainsString('10-Jul-2026', $body);
    }

    public function test_invoice_export_is_read_gated(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(AuthorizationException::class);
        app(LimoInvoiceExportController::class)->csv(Request::create('/x', 'GET'));
    }

    public function test_invoice_csv_narrows_to_the_ticked_rows(): void
    {
        $customer = $this->customer();
        $a = $this->trip($customer, 400, '2026-06-10');
        $this->trip($customer, 100, '2026-06-11');

        $invoiceId = \Modules\Limousine\Models\LimoInvoice::query()->where('booking_id', $a->id)->value('id');

        $body = $this->streamed(app(LimoInvoiceExportController::class)->csv(Request::create('/x', 'GET', [
            'ids' => (string) $invoiceId,
        ])));
        $this->assertStringContainsString('400.00', $body);
        $this->assertStringNotContainsString('100.00', $body);
    }

    public function test_invoice_pdf_with_one_ticked_row_downloads_that_invoice_document(): void
    {
        $customer = $this->customer();
        $a = $this->trip($customer, 400, '2026-06-10');
        $this->trip($customer, 100, '2026-06-11');

        $invoice = \Modules\Limousine\Models\LimoInvoice::query()->where('booking_id', $a->id)->first();

        $response = app(LimoInvoiceExportController::class)->pdf(Request::create('/x', 'GET', [
            'ids' => (string) $invoice->id,
        ]));

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'invoice-' . str_replace(['/', '\\', ' '], '-', (string) $invoice->reference) . '.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_invoice_pdf_with_several_ticked_rows_downloads_them_as_one_document(): void
    {
        $customer = $this->customer();
        $a = $this->trip($customer, 400, '2026-06-10');
        $b = $this->trip($customer, 100, '2026-06-11');
        $ids = \Modules\Limousine\Models\LimoInvoice::query()->whereIn('booking_id', [$a->id, $b->id])->pluck('id');

        $response = app(LimoInvoiceExportController::class)->pdf(Request::create('/x', 'GET', [
            'ids' => $ids->implode(','),
        ]));

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('invoices-2-', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_invoice_pdf_with_nothing_ticked_still_exports_the_tabular_report(): void
    {
        $customer = $this->customer();
        $this->trip($customer, 400, '2026-06-10');

        $response = app(LimoInvoiceExportController::class)->pdf(Request::create('/x', 'GET'));

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('limousine-invoices-', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * "Live entry data" is a backup of everything the historical import never
     * touched — see LimoInvoiceRows. A single-booking legacy invoice is
     * spotted by its booking's own imported_at; a combined/unlinked one by
     * the importer's fixed "Invoice #… | Bookings: …" notes shape.
     */
    public function test_live_entry_data_excludes_invoices_from_the_historical_import(): void
    {
        $customer = $this->customer();
        $this->trip($customer, 400, '2026-06-10'); // live, via syncInvoice()

        $legacyBooking = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => 90]);
        $legacyBooking->imported_at = now();
        $legacyBooking->save();
        \Modules\Limousine\Models\LimoInvoice::query()->create([
            'customer_id' => $customer->id, 'booking_id' => $legacyBooking->id,
            'issue_date' => '2026-05-01', 'total' => 90, 'subtotal' => 90,
        ]);

        \Modules\Limousine\Models\LimoInvoice::query()->create([
            'customer_id' => $customer->id, 'total' => 250, 'subtotal' => 250,
            'issue_date' => '2026-05-02', 'notes' => 'Invoice #1327 | Bookings: 15119, 15124',
        ]);

        $body = $this->streamed(app(LimoInvoiceExportController::class)->csv(Request::create('/x', 'GET', ['live' => '1'])));
        $this->assertStringContainsString('400.00', $body);
        $this->assertStringNotContainsString('90.00', $body);
        $this->assertStringNotContainsString('250.00', $body);
    }

    // --- Receipt -----------------------------------------------------

    public function test_receipt_export_carries_the_confirmed_column(): void
    {
        $customer = $this->customer();
        $booking = $this->trip($customer, 200, '2026-06-10');
        app(BookingPayments::class)->receive($booking, 200, 'cash');

        $body = $this->streamed(app(LimoReceiptExportController::class)->csv(Request::create('/x', 'GET')));
        $this->assertStringContainsString('Unconfirmed', $body);
    }

    public function test_receipt_export_honours_the_method_filter(): void
    {
        $customer = $this->customer();
        $cash = $this->trip($customer, 100, '2026-06-01');
        $transfer = $this->trip($customer, 150, '2026-06-02');
        app(BookingPayments::class)->receive($cash, 100, 'cash');
        app(BookingPayments::class)->receive($transfer, 150, 'transfer');

        $body = $this->streamed(app(LimoReceiptExportController::class)->csv(Request::create('/x', 'GET', ['method' => 'transfer'])));
        $this->assertStringContainsString('150.00', $body);
        $this->assertStringNotContainsString('100.00', $body);
    }

    public function test_receipt_export_honours_the_date_range_filter(): void
    {
        $customer = $this->customer();
        $earlier = $this->trip($customer, 100, '2026-06-01');
        $later = $this->trip($customer, 150, '2026-06-20');
        app(BookingPayments::class)->receive($earlier, 100, 'cash', on: Carbon::parse('2026-06-01'));
        app(BookingPayments::class)->receive($later, 150, 'cash', on: Carbon::parse('2026-06-20'));

        $body = $this->streamed(app(LimoReceiptExportController::class)->csv(Request::create('/x', 'GET', [
            'from' => '2026-06-15', 'to' => '2026-06-25',
        ])));

        $this->assertStringContainsString('150.00', $body);
        $this->assertStringNotContainsString('100.00', $body);
    }

    public function test_receipt_export_honours_the_created_by_filter(): void
    {
        $customer = $this->customer();

        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Ahmed']));
        $ahmedTrip = $this->trip($customer, 80, '2026-06-05');
        app(BookingPayments::class)->receive($ahmedTrip, 80, 'cash');

        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Mona']));
        $monaTrip = $this->trip($customer, 60, '2026-06-06');
        app(BookingPayments::class)->receive($monaTrip, 60, 'cash');

        $body = $this->streamed(app(LimoReceiptExportController::class)->csv(Request::create('/x', 'GET', [
            'prepared_by' => 'Mona',
        ])));

        $this->assertStringContainsString('60.00', $body);
        $this->assertStringNotContainsString('80.00', $body);
        $this->assertStringContainsString('Mona', $body);
    }

    /**
     * "Live entry data" excludes a receipt whose reference is the old
     * system's own number kept verbatim ("L-RCPT…") — see LimoReceiptRows.
     */
    public function test_live_entry_data_excludes_receipts_from_the_historical_import(): void
    {
        $customer = $this->customer();
        $live = $this->trip($customer, 100, '2026-06-01');
        app(BookingPayments::class)->receive($live, 100, 'cash');

        \Modules\Limousine\Models\LimoReceipt::query()->create([
            'reference' => 'L-RCPT12968', 'customer_id' => $customer->id,
            'amount' => 250, 'method' => 'cash', 'date' => '2026-05-01',
        ]);

        $body = $this->streamed(app(LimoReceiptExportController::class)->csv(Request::create('/x', 'GET', ['live' => '1'])));
        $this->assertStringContainsString('100.00', $body);
        $this->assertStringNotContainsString('250.00', $body);
    }

    // --- Quotation -----------------------------------------------------

    public function test_quotation_export_only_the_active_tab(): void
    {
        LimoQuotation::query()->create(['customer_id' => $this->customer()->id, 'fare' => 30, 'status' => LimoQuotation::STATUS_DRAFT]);
        LimoQuotation::query()->create(['customer_id' => $this->customer()->id, 'fare' => 90, 'status' => LimoQuotation::STATUS_SENT]);

        $body = $this->streamed(app(LimoQuotationExportController::class)->csv(Request::create('/x', 'GET', ['tab' => 'sent'])));
        $this->assertStringContainsString('90.00', $body);
        $this->assertStringNotContainsString('30.00', $body);
    }

    public function test_quotation_export_narrows_to_the_ticked_rows(): void
    {
        $customer = $this->customer();
        $a = LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 30, 'status' => LimoQuotation::STATUS_SENT]);
        $b = LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 90, 'status' => LimoQuotation::STATUS_SENT]);
        LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 55, 'status' => LimoQuotation::STATUS_SENT]);

        $body = $this->streamed(app(LimoQuotationExportController::class)->csv(
            Request::create('/x', 'GET', ['tab' => 'all', 'ids' => $a->id . ',' . $b->id]),
        ));
        $this->assertStringContainsString('30.00', $body);
        $this->assertStringContainsString('90.00', $body);
        $this->assertStringNotContainsString('55.00', $body);

        // Nothing ticked - or junk in the parameter - means the whole tab, as before.
        $body = $this->streamed(app(LimoQuotationExportController::class)->csv(Request::create('/x', 'GET', ['ids' => 'x,,'])));
        $this->assertStringContainsString('55.00', $body);
    }

    /**
     * "Live entry data" excludes a quotation whose reference is the old
     * system's own 4-digit "QT/0555" shape — see LimoQuotationRows. The
     * app's own auto-reference always zero-pads to 5 digits.
     */
    public function test_live_entry_data_excludes_quotations_from_the_historical_import(): void
    {
        $customer = $this->customer();
        LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 30, 'status' => LimoQuotation::STATUS_SENT]);
        LimoQuotation::query()->create([
            'reference' => 'QT/0555', 'customer_id' => $customer->id, 'fare' => 77, 'status' => LimoQuotation::STATUS_SENT,
        ]);

        $body = $this->streamed(app(LimoQuotationExportController::class)->csv(Request::create('/x', 'GET', ['live' => '1'])));
        $this->assertStringContainsString('30.00', $body);
        $this->assertStringNotContainsString('77.00', $body);
    }

    public function test_quotation_pdf_with_one_ticked_row_downloads_that_quotation_document(): void
    {
        $customer = $this->customer();
        $a = LimoQuotation::query()->create(['reference' => 'QT/00030', 'customer_id' => $customer->id, 'fare' => 30, 'status' => LimoQuotation::STATUS_SENT]);
        LimoQuotation::query()->create(['reference' => 'QT/00090', 'customer_id' => $customer->id, 'fare' => 90, 'status' => LimoQuotation::STATUS_SENT]);

        $response = app(LimoQuotationExportController::class)->pdf(Request::create('/x', 'GET', [
            'ids' => (string) $a->id,
        ]));

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('quotation-QT-00030.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_quotation_pdf_with_several_ticked_rows_downloads_them_as_one_document(): void
    {
        $customer = $this->customer();
        $a = LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 30, 'status' => LimoQuotation::STATUS_SENT]);
        $b = LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 90, 'status' => LimoQuotation::STATUS_SENT]);

        $response = app(LimoQuotationExportController::class)->pdf(Request::create('/x', 'GET', [
            'ids' => $a->id . ',' . $b->id,
        ]));

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('quotations-2-', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_quotation_pdf_with_nothing_ticked_still_exports_the_tabular_report(): void
    {
        LimoQuotation::query()->create(['customer_id' => $this->customer()->id, 'fare' => 30, 'status' => LimoQuotation::STATUS_SENT]);

        $response = app(LimoQuotationExportController::class)->pdf(Request::create('/x', 'GET'));

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('limousine-quotations-', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_quotation_search_finds_a_customer_name_or_a_reference(): void
    {
        $a = LimoQuotation::query()->create(["reference" => "QT/00777", "customer_id" => $this->customer("Gulf Air")->id, "fare" => 30, "status" => LimoQuotation::STATUS_SENT]);
        $b = LimoQuotation::query()->create(["reference" => "QT/00888", "customer_id" => $this->customer("Batelco")->id, "fare" => 90, "status" => LimoQuotation::STATUS_SENT]);

        \Livewire\Livewire::test(\Modules\Limousine\Livewire\Quotations::class)
            ->set("search", "gulf")
            ->assertSee("QT/00777")->assertDontSee("QT/00888")
            ->set("search", "00888")
            ->assertSee("QT/00888")->assertDontSee("QT/00777");

        $rows = app(\Modules\Limousine\Services\LimoQuotationRows::class)->all("all", [], false, "batelco");
        $this->assertCount(1, $rows);
        $this->assertSame("QT/00888", $rows[0]["reference"]);

    }
    public function test_the_header_box_ticks_the_page_and_the_download_links_carry_the_ids(): void
    {
        $customer = $this->customer();
        $a = LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 30, 'status' => LimoQuotation::STATUS_SENT]);
        $b = LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 90, 'status' => LimoQuotation::STATUS_SENT]);

        $component = \Livewire\Livewire::test(\Modules\Limousine\Livewire\Quotations::class)
            ->assertSee('Tick rows to export only those.')
            ->set('selectPage', true)
            ->assertSet('selected', [$b->id, $a->id])       // newest first, like the list
            ->assertSee('ids=' . $b->id . '%2C' . $a->id, false)
            ->assertSee('2 selected');

        // Ticking by hand drops the header's "all" claim; changing tab clears everything.
        $component->set('selected', [(string) $a->id])
            ->assertSet('selectPage', false)
            ->assertSee('ids=' . $a->id, false)
            ->set('tab', 'draft')
            ->assertSet('selected', [])
            ->assertSet('selectPage', false);
    }

    // --- Petty cash -----------------------------------------------------

    public function test_petty_cash_export_names_the_driver(): void
    {
        $petty = app(PettyCash::class);
        $petty->topUp(200, '2026-09-01', null, 'Qassim');
        $petty->issue(LimoDriver::query()->create(['name' => 'Hassan', 'active' => true]), 100, '2026-09-02', null, 'Mohmd');

        $body = $this->streamed(app(LimoPettyAdvanceExportController::class)->csv(Request::create('/x', 'GET')));
        $this->assertStringContainsString('Hassan', $body);
        $this->assertStringContainsString('100.00', $body);
    }

    public function test_petty_cash_export_is_read_gated(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(AuthorizationException::class);
        app(LimoPettyAdvanceExportController::class)->csv(Request::create('/x', 'GET'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
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

    // --- Quotation -----------------------------------------------------

    public function test_quotation_export_only_the_active_tab(): void
    {
        LimoQuotation::query()->create(['customer_id' => $this->customer()->id, 'fare' => 30, 'status' => LimoQuotation::STATUS_DRAFT]);
        LimoQuotation::query()->create(['customer_id' => $this->customer()->id, 'fare' => 90, 'status' => LimoQuotation::STATUS_SENT]);

        $body = $this->streamed(app(LimoQuotationExportController::class)->csv(Request::create('/x', 'GET', ['tab' => 'sent'])));
        $this->assertStringContainsString('90.00', $body);
        $this->assertStringNotContainsString('30.00', $body);
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

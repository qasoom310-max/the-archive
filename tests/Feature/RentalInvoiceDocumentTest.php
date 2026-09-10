<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Rental\Http\Controllers\RentalInvoiceController;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\RentalInvoicePdf;
use Tests\TestCase;

/**
 * The rental invoice as the customer receives it — same reference-template
 * design as the Limousine invoice/receipt/quotation, with the item-table
 * columns the owner pointed at on the old system's tax invoice: Service /
 * Vehicle / Period (From/To) / Days-Trips / Rate per Unit / Amount, and a
 * Subtotal/Discount/VAT/Total/Received/Balance box.
 */
final class RentalInvoiceDocumentTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function invoiceForOrder(float $rate = 200, int $days = 1, float $vatRate = 10, ?float $paid = null): RentalInvoice
    {
        $customer = RentalCustomer::query()->create(['name' => 'Fernandes Nunes', 'phone' => '39000000']);
        $vehicle = Vehicle::query()->create([
            'name' => 'Mercedes Vito', 'plate_no' => '626503', 'make' => 'MERCEDES', 'model' => 'VITO',
            'daily_rate' => $rate,
        ]);

        $order = RentalOrder::query()->create([
            'reference' => 'RA1788',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'start_date' => '2026-02-20',
            'end_date' => \Illuminate\Support\Carbon::parse('2026-02-20')->addDays($days)->toDateString(),
            'rate_type' => 'daily',
            'rate' => $rate,
            'vat_rate' => $vatRate,
        ]);
        $order->recalcTotals();
        $order->save();

        $invoice = $order->createInvoice();
        if ($paid !== null) {
            $invoice->forceFill(['amount_paid' => $paid])->save();
        }

        return $invoice->fresh();
    }

    public function test_the_document_carries_the_orders_vehicle_and_period(): void
    {
        $data = app(RentalInvoicePdf::class)->viewData($this->invoiceForOrder());

        $this->assertSame('Fernandes Nunes', $data['customerName']);
        $this->assertSame('RA1788', $data['orderReference']);
        $this->assertCount(1, $data['lines']);
        $this->assertSame(__('Rental'), $data['lines'][0]['service']);
        $this->assertStringContainsString('626503', $data['lines'][0]['vehicle']);
        $this->assertStringContainsString('MERCEDES', $data['lines'][0]['vehicle']);
        $this->assertSame('20-2-2026', $data['lines'][0]['from']);
    }

    /** Rate × units reproduces the order's own gross — the figure it is actually priced by. */
    public function test_the_figures_multiply_out_and_the_summary_foots_to_the_total(): void
    {
        $data = app(RentalInvoicePdf::class)->viewData($this->invoiceForOrder(rate: 200, days: 1, vatRate: 10));

        $this->assertEqualsWithDelta(200.0, $data['lines'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(200.0, $data['subtotal'], 0.001);
        $this->assertEqualsWithDelta(0.0, $data['discount'], 0.001);
        $this->assertEqualsWithDelta(10.0, $data['vatRate'], 0.001);
        $this->assertEqualsWithDelta(20.0, $data['vatAmount'], 0.001);
        $this->assertEqualsWithDelta(220.0, $data['total'], 0.001);
        $this->assertEqualsWithDelta(220.0, $data['balance'], 0.001);

        // Subtotal − discount + VAT foots to the total when there are no
        // delivery/extra/fuel charges — the same rigor the Limousine
        // statement's "every ledger row spans the same columns" test enforces.
        $this->assertEqualsWithDelta(
            $data['total'],
            $data['subtotal'] - $data['discount'] + $data['vatAmount'],
            0.001,
        );
    }

    public function test_a_part_paid_invoice_shows_what_is_still_owed(): void
    {
        $data = app(RentalInvoicePdf::class)->viewData($this->invoiceForOrder(rate: 200, vatRate: 10, paid: 100));

        $this->assertEqualsWithDelta(100.0, $data['paid'], 0.001);
        $this->assertEqualsWithDelta(120.0, $data['balance'], 0.001);
    }

    /** An invoice with no order behind it still prints an honest single line. */
    public function test_an_invoice_with_no_order_falls_back_to_one_line(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'No Order Customer']);
        $invoice = RentalInvoice::query()->create([
            'customer_id' => $customer->id,
            'reference' => 'INV/09999',
            'issue_date' => '2026-02-21',
            'subtotal' => 50, 'total' => 50, 'amount_paid' => 0,
        ]);

        $data = app(RentalInvoicePdf::class)->viewData($invoice);
        $this->assertSame([], $data['lines']);

        $html = view('rental::invoice-pdf', $data)->render();
        $this->assertStringContainsString(__('Rental services'), $html);
        $this->assertStringContainsString(\App\Erp\Views\ValueFormat::money(50.0), $html);
    }

    public function test_the_pdf_renders_and_the_download_icon_serves_it(): void
    {
        $invoice = $this->invoiceForOrder();

        $this->assertStringStartsWith('%PDF-', app(RentalInvoicePdf::class)->render($invoice));

        $response = (new RentalInvoiceController())($invoice->id, app(RentalInvoicePdf::class));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString(
            'invoice-' . str_replace(['/', '\\', ' '], '-', (string) $invoice->reference) . '.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }
}

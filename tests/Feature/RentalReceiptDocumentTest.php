<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Rental\Http\Controllers\RentalReceiptController;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\RentalReceiptPdf;
use Tests\TestCase;

/**
 * The rental receipt as the customer receives it — same reference-template
 * design as the Rental invoice/quotation, with the fields the owner pointed
 * at on the old system's printed receipt: Receipt No. / Received with
 * thanks from (+ CPR) / the sum of (principal + VAT + extra) / Rental
 * Agreement # / payment method / Remarks.
 */
final class RentalReceiptDocumentTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function orderAndInvoice(float $rate = 100, float $extraCharge = 0, float $vatRate = 10): array
    {
        $customer = RentalCustomer::query()->create(['name' => 'Abdulehah Difallah Al Otaibi', 'cpr' => '10766000', 'phone' => '39000000']);
        $vehicle = Vehicle::query()->create(['name' => 'Ford Expedition', 'plate_no' => '278003', 'daily_rate' => $rate]);

        $order = RentalOrder::query()->create([
            'reference' => 'RA1817',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'start_date' => '2026-02-20',
            'end_date' => '2026-02-21',
            'rate_type' => 'daily',
            'rate' => $rate,
            'extra_charge' => $extraCharge,
            'vat_rate' => $vatRate,
        ]);
        $order->recalcTotals();
        $order->save();

        return [$customer, $order, $order->createInvoice()];
    }

    private function receiptFor(RentalCustomer $customer, ?int $invoiceId, float $amount, string $method = 'cash', ?string $notes = null): RentalReceipt
    {
        return RentalReceipt::query()->create([
            'reference' => 'RCPT2245',
            'customer_id' => $customer->id,
            'invoice_id' => $invoiceId,
            'date' => '2026-02-21',
            'amount' => $amount,
            'method' => $method,
            'notes' => $notes,
        ])->fresh();
    }

    public function test_the_document_carries_the_customer_cpr_and_agreement(): void
    {
        [$customer, $order, $invoice] = $this->orderAndInvoice();
        $receipt = $this->receiptFor($customer, $invoice->id, (float) $invoice->total);

        $data = app(RentalReceiptPdf::class)->viewData($receipt);

        $this->assertSame('Abdulehah Difallah Al Otaibi', $data['customerName']);
        $this->assertSame('10766000', $data['customerCpr']);
        $this->assertSame('RA1817', $data['agreementReference']);
        $this->assertSame('RCPT2245', $data['reference']);
    }

    /** A receipt that pays the whole invoice shows what it's actually made of. */
    public function test_the_breakdown_shows_when_it_foots_to_the_amount(): void
    {
        [$customer, $order, $invoice] = $this->orderAndInvoice(rate: 100, extraCharge: 20, vatRate: 10);
        $receipt = $this->receiptFor($customer, $invoice->id, (float) $order->total);

        $data = app(RentalReceiptPdf::class)->viewData($receipt);

        $this->assertTrue($data['showBreakdown']);
        $this->assertEqualsWithDelta(100.0, $data['principal'], 0.001);
        $this->assertEqualsWithDelta(12.0, $data['vatAmount'], 0.001);
        $this->assertEqualsWithDelta(20.0, $data['extra'], 0.001);
        $this->assertEqualsWithDelta(
            $data['amount'],
            $data['principal'] + $data['vatAmount'] + $data['extra'],
            0.001,
        );
    }

    /** A partial payment must not print a breakdown that doesn't match what was handed over. */
    public function test_a_partial_payment_shows_no_breakdown(): void
    {
        [$customer, $order, $invoice] = $this->orderAndInvoice(rate: 100, vatRate: 10);
        $receipt = $this->receiptFor($customer, $invoice->id, 50.0);

        $data = app(RentalReceiptPdf::class)->viewData($receipt);

        $this->assertFalse($data['showBreakdown']);
    }

    /** A receipt with no invoice behind it still prints honestly, with no breakdown. */
    public function test_a_receipt_with_no_invoice_shows_no_breakdown(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'No Invoice Customer']);
        $receipt = $this->receiptFor($customer, null, 50.0);

        $data = app(RentalReceiptPdf::class)->viewData($receipt);

        $this->assertFalse($data['showBreakdown']);
        $this->assertSame('', $data['agreementReference']);
    }

    public function test_remarks_print_when_present(): void
    {
        [$customer, , $invoice] = $this->orderAndInvoice();
        $receipt = $this->receiptFor($customer, $invoice->id, (float) $invoice->total, notes: 'Abbas collected');

        $html = view('rental::receipt-pdf', app(RentalReceiptPdf::class)->viewData($receipt))->render();
        $this->assertStringContainsString(__('Remarks'), $html);
        $this->assertStringContainsString('Abbas collected', $html);
    }

    /** A receipt is our acknowledgement money arrived, not a contract — the office stamps it. */
    public function test_the_second_slot_is_the_company_stamp_not_a_customer_signature(): void
    {
        [$customer, , $invoice] = $this->orderAndInvoice();
        $receipt = $this->receiptFor($customer, $invoice->id, (float) $invoice->total);

        $html = view('rental::receipt-pdf', app(RentalReceiptPdf::class)->viewData($receipt))->render();
        $this->assertStringContainsString(__('Stamp'), $html);
        $this->assertStringNotContainsString(__('Customer signature'), $html);
    }

    public function test_the_pdf_renders_and_the_download_icon_serves_it(): void
    {
        [$customer, , $invoice] = $this->orderAndInvoice();
        $receipt = $this->receiptFor($customer, $invoice->id, (float) $invoice->total);

        $this->assertStringStartsWith('%PDF-', app(RentalReceiptPdf::class)->render($receipt));

        $response = (new RentalReceiptController())($receipt->id, app(RentalReceiptPdf::class));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString(
            'receipt-' . str_replace(['/', '\\', ' '], '-', (string) $receipt->reference) . '.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_the_receipt_forms_invoice_field_is_searchable(): void
    {
        [, , $invoice] = $this->orderAndInvoice();

        \Livewire\Livewire::test(\Modules\Rental\Livewire\ReceiptForm::class)
            ->assertSee("role=\"combobox\"", false)
            ->assertSee(__("Search invoice number or customer…"))
            ->assertSee((string) $invoice->reference)
            ->assertSee("Abdulehah Difallah Al Otaibi");
    }
}

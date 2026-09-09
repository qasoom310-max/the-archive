<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Http\Controllers\LimoInvoiceController;
use Modules\Limousine\Livewire\InvoiceForm;
use Modules\Limousine\Livewire\Invoices;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\LimoInvoicePdf;
use Tests\TestCase;

/**
 * An invoice is a document, not a workspace.
 *
 * The bill used to open a form with an editable subtotal — a screen that
 * invited the office to retype a figure the system had derived from the trip or
 * the quote. What anyone actually does with an invoice is hand it over,
 * dispatch the journey it bills for, or take the money, so those three sit on
 * its row and the form is a repair the owner reaches for.
 */
final class LimoInvoiceDocumentTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function customer(): LimoCustomer
    {
        return LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000']);
    }

    /** A billed trip, the shape the office mostly sees. */
    private function invoiceForTrip(float $fare = 45, float $paid = 0): LimoInvoice
    {
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00021', 'customer_id' => $this->customer()->id, 'fare' => $fare,
        ]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Airport',
            'to_location' => 'Hotel', 'start_at' => '2026-09-05 09:00:00', 'days' => 1,
            'rate' => $fare, 'rate_basis' => 'trip', 'net_amount' => $fare,
        ]);

        $invoice = $booking->syncInvoice();
        if ($paid > 0) {
            $invoice->forceFill(['amount_paid' => $paid, 'status' => LimoInvoice::STATUS_PARTIAL])->save();
        }

        return $invoice->fresh() ?? $invoice;
    }

    public function test_the_invoice_downloads_as_a_pdf(): void
    {
        $invoice = $this->invoiceForTrip();

        // Module routes only mount on the boot AFTER install, so the controller
        // is invoked directly — the workaround the other export tests use.
        $response = (new LimoInvoiceController())($invoice->id, app(LimoInvoicePdf::class));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString(
            'invoice-INV-',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_the_document_names_the_journeys_behind_the_total(): void
    {
        $data = app(LimoInvoicePdf::class)->viewData($this->invoiceForTrip(fare: 45, paid: 20));

        $this->assertSame('Helen Friberg', $data['customerName']);
        $this->assertSame('BK/00021', $data['bookingReference']);
        // A bill stating one figure and no journeys is one the customer has to
        // ring up to understand.
        $this->assertCount(1, $data['lines']);
        $this->assertSame('Airport', $data['lines'][0]['from']);
        $this->assertSame('BK/00021', $data['lines'][0]['booking']);
        // What they actually want from a part-paid bill.
        $this->assertEqualsWithDelta(25.0, $data['balance'], 0.001);
    }

    public function test_duplicate_imported_legs_do_not_print_the_same_journey_twice(): void
    {
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00099', 'customer_id' => $this->customer()->id, 'fare' => 55,
        ]);

        // A handful of imported bookings carry byte-identical duplicate legs
        // — an import artifact, not a genuine second journey.
        foreach (range(1, 2) as $ignored) {
            $booking->legs()->create([
                'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Al Khobar',
                'to_location' => 'Dammam airport', 'start_at' => '2026-09-01 09:00:00', 'days' => 1,
                'rate' => 55, 'rate_basis' => 'trip', 'net_amount' => 55,
            ]);
        }

        $data = app(LimoInvoicePdf::class)->viewData($booking->syncInvoice());

        $this->assertCount(1, $data['lines']);
    }

    public function test_an_invoice_raised_from_a_quote_borrows_the_quotes_legs(): void
    {
        $quote = LimoQuotation::query()->create(['customer_id' => $this->customer()->id, 'fare' => 30]);
        $quote->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Seef',
            'to_location' => 'Manama', 'start_at' => now(), 'days' => 1,
            'rate' => 30, 'rate_basis' => 'trip', 'net_amount' => 30,
        ]);

        // Between billing and dispatch there is no trip yet, so the quote is
        // the only thing that knows the journey.
        $data = app(LimoInvoicePdf::class)->viewData($quote->convertToInvoice());

        $this->assertCount(1, $data['lines']);
        $this->assertSame('Seef', $data['lines'][0]['from']);
    }

    public function test_a_legacy_invoice_with_no_single_booking_stays_a_single_line(): void
    {
        $customer = $this->customer();

        // The old system let one invoice legitimately span several bookings;
        // this schema's booking_id is a single FK, so a handful of invoices
        // carried over from the old system's own export have neither
        // booking_id nor quotation_id set, and their notes carry a free-text
        // summary of every booking they once covered ("Bookings: 15119,
        // 15124, ..."). That text is NOT a reliable source of line items —
        // parsing it back into per-booking rows once printed dozens of a
        // customer's unrelated trips on a single bill, which read as "the
        // whole history of his invoices" rather than this one document. The
        // bill stays a single line for what it actually knows: its own total.
        $invoice = LimoInvoice::query()->create([
            'customer_id' => $customer->id,
            'reference' => 'INV/09999',
            'issue_date' => '2026-09-03',
            'subtotal' => 35, 'total' => 35, 'amount_paid' => 0,
            'notes' => 'Invoice #9999 | Bookings: 15119, 15124, 15131',
        ]);

        $data = app(LimoInvoicePdf::class)->viewData($invoice);

        // No structured booking/quotation to itemize — the document's own
        // @empty fallback prints the single placeholder line, not lines().
        $this->assertSame([], $data['lines']);

        // The raw notes text is still printed verbatim in the Notes
        // paragraph — this pins that the ITEM TABLE doesn't explode it into
        // one row per mentioned booking, not that the number disappears
        // from the document entirely.
        $html = view('limousine::invoice-pdf', $data)->render();
        $itemTable = substr($html, (int) strpos($html, 'class="items"'), (int) strpos($html, '</table>') - (int) strpos($html, 'class="items"'));
        $this->assertStringNotContainsString('15119', $itemTable);
        $this->assertStringContainsString(__('Limousine services'), $itemTable);
        $this->assertStringContainsString(\App\Erp\Views\ValueFormat::money($data['subtotal']), $itemTable);
    }

    public function test_the_row_offers_the_document_rather_than_an_editor(): void
    {
        $invoice = $this->invoiceForTrip();

        Livewire::test(Invoices::class)
            ->assertDontSeeHtml("window.location='" . url('/app/limousine/invoice/' . $invoice->id) . "'")
            ->assertSeeHtml(url('/app/limousine/invoice/' . $invoice->id . '/download'))
            ->assertSee(__('Receive payment'));
    }

    public function test_money_taken_on_the_row_writes_the_same_receipt(): void
    {
        $invoice = $this->invoiceForTrip(fare: 45);

        Livewire::test(Invoices::class)
            ->call('openCollect', $invoice->id)
            // Offered as the whole balance, which is what is being asked for.
            ->assertSet('collectAmount', '45')
            ->call('saveCollect')
            ->assertHasNoErrors();

        $this->assertSame(LimoInvoice::STATUS_PAID, $invoice->fresh()?->status);
        // One truth: the same receipt the counter would have written.
        $this->assertSame(1, LimoReceipt::query()->count());
        $this->assertSame($invoice->id, LimoReceipt::query()->firstOrFail()->invoice_id);
    }

    public function test_a_bill_with_no_trip_behind_it_cannot_take_money_from_the_row(): void
    {
        $quote = LimoQuotation::query()->create(['customer_id' => $this->customer()->id, 'fare' => 30]);
        $invoice = $quote->convertToInvoice();

        // Billing first is fine; taking money for a journey that does not exist
        // yet is not.
        Livewire::test(Invoices::class)
            ->call('openCollect', $invoice->id)
            ->call('saveCollect')
            ->assertHasErrors('collectAmount');

        $this->assertSame(0, LimoReceipt::query()->count());
    }

    public function test_the_trip_is_dispatched_from_the_row(): void
    {
        $quote = LimoQuotation::query()->create(['customer_id' => $this->customer()->id, 'fare' => 30]);
        $quote->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Seef',
            'to_location' => 'Manama', 'start_at' => now(), 'days' => 1,
            'rate' => 30, 'rate_basis' => 'trip', 'net_amount' => 30,
        ]);
        $invoice = $quote->convertToInvoice();

        Livewire::test(Invoices::class)->call('createTrip', $invoice->id);

        $booking = LimoBooking::query()->firstOrFail();
        $this->assertSame($booking->id, $invoice->fresh()?->booking_id);
    }

    public function test_correcting_a_bill_by_hand_is_the_owners_alone(): void
    {
        $invoice = $this->invoiceForTrip();

        // Nothing links to this screen any more — the list carries every action
        // an invoice is for. It survives as a repair, because retyping a total
        // overrides a figure the trip or the quote decided.
        Livewire::test(InvoiceForm::class, ['id' => $invoice->id])->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        Livewire::test(InvoiceForm::class, ['id' => $invoice->id])->assertOk();
    }

    public function test_raising_a_bill_from_a_quote_stays_open_to_the_office(): void
    {
        // The owner-only rule is about correcting an existing bill, not about
        // issuing one — a quote is billed by whoever takes the booking.
        Livewire::test(InvoiceForm::class)->assertOk();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Limousine\Http\Controllers\LimoCombinedInvoiceController;
use Modules\Limousine\Livewire\Invoices;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\LimoCombinedInvoicePdf;
use Modules\Limousine\Support\LegacyInvoiceBookings;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * A month of work as one document, and the search that gathers it.
 *
 * A company that ran forty trips does not want forty PDFs — it wants one
 * schedule it can check line by line and pay against.
 */
final class LimoCombinedInvoiceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function customer(string $name = 'Dadabhai Travel'): LimoCustomer
    {
        return LimoCustomer::query()->create(['name' => $name, 'type' => 'company']);
    }

    /** A billed trip with one leg, dated where the test wants it. */
    private function trip(LimoCustomer $customer, float $fare, string $issued, string $pax = 'Helen'): LimoInvoice
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'fare' => $fare,
            'pax_name' => $pax, 'company_reference' => 'PO-4471',
        ]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Airport',
            'to_location' => 'Hotel', 'start_at' => $issued . ' 09:00:00', 'days' => 1,
            // What the office WROTE at booking vs the car the queue later
            // assigned — the document must print the first.
            'vehicle_details' => 'GMC Yukon', 'vehicle' => 'Unit 7 · 12345 · Black',
            'rate' => $fare, 'rate_basis' => 'trip', 'net_amount' => $fare,
        ]);

        $invoice = $booking->syncInvoice();
        $invoice->forceFill(['issue_date' => $issued])->save();

        return $invoice->fresh() ?? $invoice;
    }

    public function test_the_list_searches_the_bill_the_customer_and_the_trip(): void
    {
        $mine = $this->trip($this->customer(), 400, '2026-06-10');
        $theirs = $this->trip($this->customer('Gulf Air'), 100, '2026-06-11');

        Livewire::test(Invoices::class)
            ->set('search', 'Dadabhai')
            ->assertSee((string) $mine->reference)
            ->assertDontSee((string) $theirs->reference);

        // And by the trip it bills for, which is what the office has in hand.
        Livewire::test(Invoices::class)
            ->set('search', (string) $theirs->booking->reference)
            ->assertSee((string) $theirs->reference)
            ->assertDontSee((string) $mine->reference);
    }

    public function test_the_issue_date_window_is_how_a_month_is_asked_for(): void
    {
        $customer = $this->customer();
        $june = $this->trip($customer, 400, '2026-06-10');
        $july = $this->trip($customer, 100, '2026-07-02');

        Livewire::test(Invoices::class)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-30')
            ->assertSee((string) $june->reference)
            ->assertDontSee((string) $july->reference);
    }

    /** Issued on 1 September for an August trip: that is August's bill, not September's. */
    public function test_the_window_reads_invoices_by_when_the_trips_ran(): void
    {
        $customer = $this->customer('Braxtone Plus W.L.L');
        $august = $this->trip($customer, 14, '2026-08-01');
        $august->forceFill(['issue_date' => '2026-09-01'])->save();
        $september = $this->trip($customer, 8, '2026-09-23');
        $fee = LimoInvoice::query()->create([
            'customer_id' => $customer->id, 'issue_date' => '2026-09-15', 'total' => 5, 'subtotal' => 5,
            'charge_label' => 'Late payment fee',
        ]);

        $this->assertSame('2026-08-01', $august->refresh()->service_date?->toDateString());

        $component = Livewire::test(Invoices::class)
            ->set('from', '2026-09-01')
            ->set('to', '2026-09-30')
            ->assertSee((string) $september->reference)
            ->assertDontSee((string) $august->reference)
            // A charge with no trip goes by the day it was raised.
            ->assertSee((string) $fee->reference)
            ->call('selectAll');
        $this->assertNotContains($august->id, $component->get('selected'));

        Livewire::test(Invoices::class)
            ->set('from', '2026-08-01')
            ->set('to', '2026-08-31')
            ->assertSee((string) $august->reference);

        // The bill's Period is the month asked for.
        $data = app(LimoCombinedInvoicePdf::class)->viewData(LimoInvoice::query()->whereKey($september->id)->get(), '2026-09-01', '2026-09-30');
        $this->assertSame('2026-09-01', $data['periodFrom']->toDateString());
        $this->assertSame('2026-09-30', $data['periodTo']->toDateString());
    }

    /** Moving a trip to another day moves its bill with it. */
    public function test_editing_a_trip_date_moves_the_invoice_to_that_month(): void
    {
        $invoice = $this->trip($this->customer(), 14, '2026-09-30');
        $leg = $invoice->booking->legs()->firstOrFail();

        $leg->start_at = \Illuminate\Support\Carbon::parse('2026-10-02 09:00:00');
        $leg->save();

        $this->assertSame('2026-10-02', $invoice->refresh()->service_date?->toDateString());
    }

    /** An old invoice imported before its bookings takes their date once they arrive. */
    public function test_an_old_invoice_takes_its_date_when_its_bookings_arrive(): void
    {
        $customer = $this->customer('Braxtone Plus W.L.L');
        $invoice = $this->oldInvoice($customer, 1327, 22, 'Invoice #1327 | Bookings: 15119, 15124');
        $this->assertNull($invoice->refresh()->service_date);

        $this->oldBooking($customer, 15119, 8, 'Noora');

        $this->assertSame('2026-09-05', $invoice->refresh()->service_date?->toDateString());
    }

    /** A ticked bill from another month is not printed under this month's Period. */
    public function test_the_period_never_claims_a_month_its_rows_are_not_in(): void
    {
        $customer = $this->customer();
        $august = $this->trip($customer, 14, '2026-08-20');
        $september = $this->trip($customer, 8, '2026-09-23');

        $data = app(LimoCombinedInvoicePdf::class)->viewData(
            LimoInvoice::query()->whereKey([$august->id, $september->id])->get(), '2026-09-01', '2026-09-30',
        );

        $this->assertSame('2026-08-20', $data['periodFrom']->toDateString());
        $this->assertSame('2026-09-23', $data['periodTo']->toDateString());
    }

    public function test_select_all_takes_the_whole_filter_not_just_the_page(): void
    {
        $customer = $this->customer();
        $this->trip($customer, 400, '2026-06-10');
        $this->trip($customer, 100, '2026-06-20');
        $outside = $this->trip($customer, 50, '2026-07-01');

        $component = Livewire::test(Invoices::class)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-30')
            ->call('selectAll');

        $this->assertCount(2, $component->get('selected'));
        $this->assertNotContains($outside->id, $component->get('selected'));
    }

    public function test_two_customers_at_once_are_refused_rather_than_quietly_trimmed(): void
    {
        $mine = $this->trip($this->customer(), 400, '2026-06-10');
        $theirs = $this->trip($this->customer('Gulf Air'), 100, '2026-06-11');

        // A document addressed to two companies is not a document.
        Livewire::test(Invoices::class)
            ->set('selected', [$mine->id, $theirs->id])
            ->assertSet('selected', [$mine->id, $theirs->id])
            ->assertViewHas('mixedCustomers', true)
            ->assertViewHas('combinedUrl', null);
    }

    public function test_the_document_lists_a_row_per_trip_with_what_the_customer_reconciles(): void
    {
        $customer = $this->customer();
        $first = $this->trip($customer, 400, '2026-06-10', pax: 'Helen Friberg');
        $second = $this->trip($customer, 100, '2026-06-20', pax: 'Omar Ali');

        $data = app(LimoCombinedInvoicePdf::class)->viewData(
            LimoInvoice::query()->whereIn('id', [$first->id, $second->id])->orderBy('issue_date')->get()
        );

        $this->assertCount(2, $data['rows']);
        $this->assertSame(1, $data['rows'][0]['serial']);
        $this->assertSame('Helen Friberg', $data['rows'][0]['pax']);
        $this->assertSame('GMC Yukon', $data['rows'][0]['vehicle']);
        // Never the plate the queue assigned.
        $this->assertStringNotContainsString('12345', $data['rows'][0]['vehicle']);
        $this->assertSame('PO-4471', $data['rows'][0]['company_reference']);
        $this->assertSame($first->booking->reference, $data['rows'][0]['booking']);
        $this->assertSame('Omar Ali', $data['rows'][1]['pax']);
        $this->assertEqualsWithDelta(500.0, $data['total'], 0.001);
    }

    /**
     * A cancelled trip is out of the fare, so printing it made the document
     * list a journey that never ran and then take it off again on a
     * "Discount" line.
     */
    public function test_a_cancelled_trip_is_not_printed(): void
    {
        $customer = $this->customer();
        $invoice = $this->trip($customer, 400, '2026-06-10');
        $booking = $invoice->booking;
        $booking->legs()->create([
            'sequence' => 1, 'service_type' => 'transfer', 'from_location' => 'Hotel',
            'to_location' => 'Airport', 'start_at' => '2026-06-12 09:00:00', 'days' => 1,
            'rate' => 150, 'rate_basis' => 'trip', 'net_amount' => 150,
            'status' => \Modules\Limousine\Models\LimoLeg::STATUS_CANCELLED,
        ]);
        $booking->recalcTotal();
        $booking->save();
        $booking->syncInvoice();

        $data = app(LimoCombinedInvoicePdf::class)->viewData(
            LimoInvoice::query()->where('id', $invoice->id)->get()
        );

        $this->assertCount(1, $data['rows']);
        $this->assertEqualsWithDelta(400.0, $data['total'], 0.001);
    }

    /** A car picked from the fleet with no type typed prints its model, never the plate. */
    public function test_a_car_picked_from_the_fleet_prints_its_model(): void
    {
        $customer = $this->customer();
        $invoice = $this->trip($customer, 400, '2026-06-10');
        $invoice->booking->legs()->update(['vehicle_details' => null, 'vehicle' => 'Ford Expedition · 363899 · White']);

        $data = app(LimoCombinedInvoicePdf::class)->viewData(
            LimoInvoice::query()->where('id', $invoice->id)->get()
        );

        $this->assertSame('Ford Expedition', $data['rows'][0]['vehicle']);
    }

    /** A private customer with no passenger typed is the passenger. */
    public function test_a_private_customers_own_name_fills_a_blank_passenger(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'type' => 'individual']);
        $invoice = $this->trip($customer, 40, '2026-06-10', pax: '.');

        $data = app(LimoCombinedInvoicePdf::class)->viewData(
            LimoInvoice::query()->where('id', $invoice->id)->get()
        );

        $this->assertSame('Helen Friberg', $data['rows'][0]['pax']);
    }

    public function test_a_bill_whose_trips_do_not_sum_to_it_says_so_on_its_own_line(): void
    {
        $customer = $this->customer();
        $invoice = $this->trip($customer, 400, '2026-06-10');
        // A discount applied to the bill after the trip was priced.
        $invoice->forceFill(['discount' => 40, 'total' => 360])->save();

        $data = app(LimoCombinedInvoicePdf::class)->viewData(
            LimoInvoice::query()->where('id', $invoice->id)->get()
        );

        // Stated, not left for the reader to find by adding up the column.
        $this->assertCount(2, $data['rows']);
        $this->assertEqualsWithDelta(-40.0, $data['rows'][1]['total'], 0.001);
        $this->assertEqualsWithDelta(360.0, $data['total'], 0.001);
    }

    public function test_a_charge_with_no_journey_still_gets_a_line(): void
    {
        $customer = $this->customer();
        $fee = LimoInvoice::query()->create([
            'customer_id' => $customer->id, 'issue_date' => '2026-07-01',
            'subtotal' => 25, 'total' => 25, 'charge_label' => 'Late payment charge — June 2026',
        ]);

        // It is money owed, so it cannot vanish from a document that has to add
        // up to what is being asked for.
        $data = app(LimoCombinedInvoicePdf::class)->viewData(
            LimoInvoice::query()->where('id', $fee->id)->get()
        );

        $this->assertCount(1, $data['rows']);
        $this->assertSame('Late payment charge — June 2026', $data['rows'][0]['service']);
        $this->assertEqualsWithDelta(25.0, $data['total'], 0.001);
    }

    public function test_it_downloads_as_one_pdf(): void
    {
        $customer = $this->customer();
        $a = $this->trip($customer, 400, '2026-06-10');
        $b = $this->trip($customer, 100, '2026-06-20');

        // Module routes only mount on the boot AFTER install, so the controller
        // is invoked directly — the workaround the other export tests use.
        $response = (new LimoCombinedInvoiceController())(
            Request::create('/', 'GET', ['ids' => $a->id . ',' . $b->id]),
            app(LimoCombinedInvoicePdf::class),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('invoice-Dadabhai-Travel', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_download_refuses_a_mixed_bag_even_when_asked_directly(): void
    {
        $mine = $this->trip($this->customer(), 400, '2026-06-10');
        $theirs = $this->trip($this->customer('Gulf Air'), 100, '2026-06-11');

        // The ids come off a query string, so the rule is enforced here too and
        // not only by what the page offers.
        $this->expectException(HttpException::class);

        (new LimoCombinedInvoiceController())(
            Request::create('/', 'GET', ['ids' => $mine->id . ',' . $theirs->id]),
            app(LimoCombinedInvoicePdf::class),
        );
    }

    /** An old-system booking, keeping the old number as its id, with one trip. */
    private function oldBooking(LimoCustomer $customer, int $id, float $fare, string $pax): LimoBooking
    {
        $booking = new LimoBooking();
        $booking->forceFill([
            'id' => $id, 'customer_id' => $customer->id, 'fare' => $fare,
            'pax_name' => $pax, 'company_reference' => 'BTRSA/' . $id,
        ])->save();
        $booking->forceFill(['imported_at' => now()])->saveQuietly();
        // The old system kept one vehicle field; the import put it in `vehicle`.
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Hamad Town',
            'to_location' => 'Airport', 'start_at' => '2026-09-05 09:00:00', 'days' => 1,
            'vehicle' => 'Sedan', 'rate' => $fare, 'rate_basis' => 'trip', 'net_amount' => $fare,
        ]);

        return $booking;
    }

    private function oldInvoice(LimoCustomer $customer, int $number, float $total, ?string $notes): LimoInvoice
    {
        $invoice = new LimoInvoice();
        $invoice->forceFill([
            'id' => $number, 'customer_id' => $customer->id, 'issue_date' => '2026-09-10',
            'subtotal' => $total, 'discount' => 0, 'total' => $total, 'amount_paid' => 0,
            'status' => LimoInvoice::STATUS_UNPAID, 'notes' => $notes,
        ])->save();

        return $invoice;
    }

    /** An invoice the old system raised for several trips prints each of them. */
    public function test_an_old_invoice_for_several_bookings_prints_their_trips(): void
    {
        $customer = $this->customer('Braxtone Plus W.L.L');
        $this->oldBooking($customer, 15119, 8, 'Noora');
        $this->oldBooking($customer, 15124, 14, 'Abdulla');
        $invoice = $this->oldInvoice($customer, 1327, 22, 'Invoice #1327 | Bookings: 15119, 15124');

        $rows = app(LimoCombinedInvoicePdf::class)->viewData(LimoInvoice::query()->whereKey($invoice->id)->get())['rows'];

        $this->assertCount(2, $rows);
        $this->assertSame('BK/15119', $rows[0]['booking']);
        $this->assertSame('Sedan', $rows[0]['vehicle']);
        $this->assertSame('Hamad Town', $rows[0]['from']);
        $this->assertSame('BTRSA/15119', $rows[0]['company_reference']);
        $this->assertSame('Noora', $rows[0]['pax']);
        $this->assertSame('BK/15124', $rows[1]['booking']);
        $this->assertNotSame('', $rows[1]['date']);
    }

    /** One waiting for its booking gets linked once the booking is on file. */
    public function test_an_old_invoice_waiting_for_its_booking_is_linked_to_it(): void
    {
        $customer = $this->customer('Braxtone Plus W.L.L');
        $invoice = $this->oldInvoice($customer, 1340, 8, 'Invoice #1340 | Bookings: 15523');
        $booking = $this->oldBooking($customer, 15523, 8, 'Jaber');
        $receipt = new LimoReceipt();
        $receipt->forceFill(['customer_id' => $customer->id, 'booking_id' => $booking->id, 'amount' => 8, 'date' => '2026-09-12'])->save();

        $migration = require base_path('Modules/Limousine/database/migrations/2026_10_06_950041_link_legacy_invoices_to_their_booking.php');
        $migration->up();

        $invoice->refresh();
        $this->assertSame($booking->id, $invoice->booking_id);
        $this->assertSame($invoice->id, $receipt->refresh()->invoice_id);
        $this->assertSame(LimoInvoice::STATUS_PAID, $invoice->status);

        $rows = app(LimoCombinedInvoicePdf::class)->viewData(LimoInvoice::query()->whereKey($invoice->id)->get())['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('BK/15523', $rows[0]['booking']);
        $this->assertSame('Jaber', $rows[0]['pax']);
    }

    /** The old export cut INV/01327's list off at "152": the stub goes and the hidden trips come back. */
    public function test_a_booking_list_cut_off_mid_number_is_completed(): void
    {
        $customer = $this->customer('Braxtone Plus W.L.L');
        $stranger = $this->customer('Someone Else');
        $this->oldBooking($customer, 15119, 8, 'Noora');
        $this->oldBooking($customer, 15225, 24, 'Ameera');
        $this->oldBooking($stranger, 152, 70, 'Nobody'); // what "152" used to match
        $this->oldBooking($customer, 15230, 14, 'Hidden one');
        $this->oldBooking($customer, 15231, 8, 'Hidden two');
        $this->oldBooking($customer, 15240, 8, 'Billed elsewhere');
        $this->oldInvoice($customer, 1390, 8, 'Invoice #1390 | Bookings: 15240');
        $invoice = $this->oldInvoice($customer, 1327, 54, 'Invoice #1327 | Bookings: 15119, 15225, 152');

        LegacyInvoiceBookings::completeTruncated();

        $this->assertSame('Invoice #1327 | Bookings: 15119, 15225, 15230, 15231', $invoice->refresh()->notes);
        $rows = app(LimoCombinedInvoicePdf::class)->viewData(LimoInvoice::query()->whereKey($invoice->id)->get())['rows'];
        $this->assertSame(['BK/15119', 'BK/15225', 'BK/15230', 'BK/15231'], array_column($rows, 'booking'));
    }

    /** Without an exact match, only the stub is dropped. */
    public function test_a_cut_off_list_that_cannot_be_matched_only_loses_the_stub(): void
    {
        $customer = $this->customer('Braxtone Plus W.L.L');
        $this->oldBooking($customer, 15119, 8, 'Noora');
        $this->oldBooking($customer, 15230, 14, 'Too much');
        $invoice = $this->oldInvoice($customer, 1327, 20, 'Invoice #1327 | Bookings: 15119, 152');

        LegacyInvoiceBookings::completeTruncated();

        $this->assertSame('Invoice #1327 | Bookings: 15119', $invoice->refresh()->notes);
    }

    /** An ERP invoice the restore brought back bare is tied to the booking it was raised for. */
    public function test_an_erp_invoice_that_lost_its_booking_is_tied_back(): void
    {
        $customer = $this->customer('Braxtone Plus W.L.L');
        $a = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => 8, 'pax_name' => 'Leena']);
        $b = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => 8, 'pax_name' => 'Mahmood']);
        LimoInvoice::query()->whereIn('booking_id', [$a->id, $b->id])->delete();
        $day = $a->created_at?->toDateString();
        $first = $this->oldInvoice($customer, 1340, 8, 'Imported from previous system.');
        $second = $this->oldInvoice($customer, 1341, 8, 'Imported from previous system.');
        LimoInvoice::query()->whereKey([$first->id, $second->id])->update(['issue_date' => $day]);

        LegacyInvoiceBookings::linkRestored();

        $this->assertSame($a->id, $first->refresh()->booking_id);
        $this->assertSame($b->id, $second->refresh()->booking_id);
    }

    /** Two bare invoices and one possible booking: nothing is guessed. */
    public function test_an_erp_invoice_is_not_guessed_when_the_counts_differ(): void
    {
        $customer = $this->customer('Braxtone Plus W.L.L');
        $a = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => 8]);
        LimoInvoice::query()->where('booking_id', $a->id)->delete();
        $day = $a->created_at?->toDateString();
        $first = $this->oldInvoice($customer, 1340, 8, null);
        $second = $this->oldInvoice($customer, 1341, 8, null);
        LimoInvoice::query()->whereKey([$first->id, $second->id])->update(['issue_date' => $day]);

        LegacyInvoiceBookings::linkRestored();

        $this->assertNull($first->refresh()->booking_id);
        $this->assertNull($second->refresh()->booking_id);
    }

    /** A booking that already has its own invoice is not billed a second time. */
    public function test_an_old_invoice_is_not_linked_to_a_booking_that_already_has_an_invoice(): void
    {
        $customer = $this->customer();
        $booking = $this->oldBooking($customer, 15600, 8, 'Hasan');
        $own = $this->oldInvoice($customer, 1500, 8, null);
        $own->forceFill(['booking_id' => $booking->id])->save();
        $waiting = $this->oldInvoice($customer, 1501, 8, 'Invoice #1501 | Bookings: 15600');

        LegacyInvoiceBookings::linkWaiting();

        $this->assertNull($waiting->refresh()->booking_id);
    }
}

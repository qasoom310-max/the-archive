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
use Modules\Limousine\Services\LimoCombinedInvoicePdf;
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
}

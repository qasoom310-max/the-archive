<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Invoices;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoBulkInvoice;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\BulkInvoices;
use Tests\TestCase;

/**
 * A bulk invoice: several of one customer's invoices saved as one document
 * that can be followed and paid as one. An invoice is in one at most.
 */
final class LimoBulkInvoiceTest extends TestCase
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

    private function trip(LimoCustomer $customer, float $fare, string $day): LimoInvoice
    {
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => $fare, 'pax_name' => 'Helen']);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Airport',
            'to_location' => 'Hotel', 'start_at' => $day . ' 09:00:00', 'days' => 1,
            'rate' => $fare, 'rate_basis' => 'trip', 'net_amount' => $fare,
        ]);

        $invoice = $booking->syncInvoice();
        $invoice->forceFill(['issue_date' => $day])->save();

        return $invoice->fresh() ?? $invoice;
    }

    public function test_ticked_invoices_are_saved_as_one_bulk_invoice(): void
    {
        $customer = $this->customer();
        $a = $this->trip($customer, 40, '2026-06-10');
        $b = $this->trip($customer, 60, '2026-06-12');
        $other = $this->trip($customer, 15, '2026-06-20');

        Livewire::test(Invoices::class)
            ->set('selected', [$a->id, $b->id])
            ->call('createBulk')
            ->assertHasNoErrors()
            ->assertSet('tab', 'bulk')
            ->assertSet('selected', [])
            ->assertSee('BINV/00001')
            ->assertSee('Dadabhai Travel');

        $bulk = LimoBulkInvoice::query()->with('invoices')->firstOrFail();
        $this->assertSame('BINV/00001', $bulk->reference);
        $this->assertSame($customer->id, $bulk->customer_id);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $bulk->invoices->modelKeys());
        $this->assertSame(100.0, $bulk->total());
        $this->assertSame(LimoBulkInvoice::STATUS_UNPAID, $bulk->status());
        $this->assertNull($other->fresh()?->bulk_invoice_id);
    }

    public function test_an_invoice_cannot_go_in_a_second_bulk_invoice(): void
    {
        $customer = $this->customer();
        $a = $this->trip($customer, 40, '2026-06-10');
        $b = $this->trip($customer, 60, '2026-06-12');
        $c = $this->trip($customer, 25, '2026-06-14');

        app(BulkInvoices::class)->create([$a->id, $b->id]);

        Livewire::test(Invoices::class)
            ->set('selected', [$b->id, $c->id])
            ->assertSee('Already in a bulk invoice')
            ->assertDontSeeHtml('data-make-bulk')
            ->call('createBulk')
            ->assertHasErrors('bulk')
            ->call('untickBulked')
            ->assertSet('selected', [$c->id]);

        $this->assertSame(1, LimoBulkInvoice::query()->count());
        $this->assertNull($c->fresh()?->bulk_invoice_id);
    }

    public function test_two_customers_cannot_share_a_bulk_invoice(): void
    {
        $a = $this->trip($this->customer(), 40, '2026-06-10');
        $b = $this->trip($this->customer('Gulf Air'), 60, '2026-06-12');

        $this->expectException(ValidationException::class);
        app(BulkInvoices::class)->create([$a->id, $b->id]);
    }

    public function test_one_payment_is_shared_over_its_invoices_oldest_first(): void
    {
        $customer = $this->customer();
        $old = $this->trip($customer, 40, '2026-06-10');
        $new = $this->trip($customer, 60, '2026-06-12');
        $outside = $this->trip($customer, 99, '2026-06-01');
        $bulk = app(BulkInvoices::class)->create([$new->id, $old->id]);

        Livewire::test(Invoices::class)
            ->set('tab', 'bulk')
            ->call('openBulkPay', $bulk->id)
            ->assertSet('collectAmount', '100')
            ->set('collectAmount', '70')
            ->set('collectMethod', 'transfer')
            ->call('saveBulkPay')
            ->assertHasNoErrors()
            ->assertSet('payingBulkId', null);

        $this->assertSame(LimoInvoice::STATUS_PAID, $old->fresh()?->status);
        $this->assertSame(30.0, $new->fresh()?->amount_paid);
        // An older bill that is not in this bulk invoice gets none of it.
        $this->assertSame(0.0, $outside->fresh()?->amount_paid);
        $this->assertSame(2, LimoReceipt::query()->count());
        $this->assertStringContainsString('BINV/00001', (string) LimoReceipt::query()->value('notes'));

        $bulk = LimoBulkInvoice::query()->with('invoices')->findOrFail($bulk->id);
        $this->assertSame(LimoBulkInvoice::STATUS_PARTIAL, $bulk->status());
        $this->assertSame(30.0, $bulk->balance());

        app(BulkInvoices::class)->pay($bulk, 30);
        $this->assertSame(LimoBulkInvoice::STATUS_PAID, LimoBulkInvoice::query()->with('invoices')->findOrFail($bulk->id)->status());
    }

    public function test_it_refuses_more_than_is_left(): void
    {
        $customer = $this->customer();
        $bulk = app(BulkInvoices::class)->create([$this->trip($customer, 40, '2026-06-10')->id]);

        Livewire::test(Invoices::class)
            ->call('openBulkPay', $bulk->id)
            ->set('collectAmount', '41')
            ->call('saveBulkPay')
            ->assertHasErrors('collectAmount');

        $this->assertSame(0, LimoReceipt::query()->count());
    }

    public function test_cancelling_frees_the_invoices_and_keeps_the_record(): void
    {
        $customer = $this->customer();
        $a = $this->trip($customer, 40, '2026-06-10');
        $bulk = app(BulkInvoices::class)->create([$a->id]);

        Livewire::test(Invoices::class)->set('tab', 'bulk')->call('cancelBulk', $bulk->id)->assertSee('Cancelled');

        $this->assertNull($a->fresh()?->bulk_invoice_id);
        $this->assertTrue((bool) $bulk->fresh()?->isCancelled());

        // Free again, so it can go in a new one.
        $again = app(BulkInvoices::class)->create([$a->id]);
        $this->assertSame('BINV/00002', $again->reference);
    }

    public function test_only_a_manager_can_cancel(): void
    {
        $bulk = app(BulkInvoices::class)->create([$this->trip($this->customer(), 40, '2026-06-10')->id]);

        $this->grantEveryone('limousine.invoice');
        $this->actingAs(User::factory()->create());

        Livewire::test(Invoices::class)->set('tab', 'bulk')->call('cancelBulk', $bulk->id)->assertForbidden();
        $this->assertFalse((bool) $bulk->fresh()?->isCancelled());
    }

    public function test_it_can_be_marked_sent_and_its_invoices_opened(): void
    {
        $customer = $this->customer();
        $in = $this->trip($customer, 40, '2026-06-10');
        $out = $this->trip($customer, 60, '2026-06-12');
        $bulk = app(BulkInvoices::class)->create([$in->id]);

        Livewire::test(Invoices::class)
            ->set('tab', 'bulk')
            ->call('toggleBulkSent', $bulk->id)
            ->call('showBulk', $bulk->id)
            ->assertSet('tab', 'all')
            ->assertSee((string) $in->reference)
            ->assertDontSee((string) $out->reference);

        $this->assertNotNull($bulk->fresh()?->sent_at);
    }

    public function test_it_downloads_as_one_pdf_with_its_own_number(): void
    {
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();

        $customer = $this->customer();
        $bulk = app(BulkInvoices::class)->create([
            $this->trip($customer, 40, '2026-06-10')->id,
            $this->trip($customer, 60, '2026-06-12')->id,
        ]);

        $response = $this->get('/app/limousine/invoice/bulk/' . $bulk->id . '/download');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('tax-invoice-BINV-00001.pdf', (string) $response->headers->get('Content-Disposition'));

        $html = view('limousine::combined-invoice-pdf', [
            ...app(\Modules\Limousine\Services\LimoCombinedInvoicePdf::class)->viewData($bulk->invoices()->get()),
            'bulkReference' => 'BINV/00001',
        ])->render();
        $this->assertStringContainsString('BINV/00001', $html);
        $this->assertStringContainsString('Tax Invoice BINV/00001', $html);
    }
}

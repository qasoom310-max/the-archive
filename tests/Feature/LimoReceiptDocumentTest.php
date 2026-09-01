<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Limousine\Http\Controllers\LimoReceiptController;
use Modules\Limousine\Livewire\Receipts;
use Modules\Limousine\Mail\ReceiptMail;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\LimoReceiptPdf;
use Tests\TestCase;

/**
 * A receipt the customer can actually be given.
 *
 * A row in our list is our record, not theirs. This is the document they keep:
 * what was paid, when, against which job, and what is still owed — downloadable
 * and sendable, because a receipt nobody can hand over is only a database row.
 */
final class LimoReceiptDocumentTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function receipt(float $amount = 40, ?float $balance = 20): LimoReceipt
    {
        $customer = LimoCustomer::query()->create(['name' => 'Qassim Makhlooq', 'phone' => '39000000']);
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00007', 'customer_id' => $customer->id,
            'status' => LimoBooking::STATUS_CONFIRMED,
        ]);

        return LimoReceipt::query()->create([
            'customer_id' => $customer->id,
            'booking_id' => $booking->id,
            'date' => now(),
            'amount' => $amount,
            'balance_after' => $balance,
            'method' => 'cash',
        ]);
    }

    public function test_the_receipt_downloads_as_a_pdf_naming_the_job_and_the_balance(): void
    {
        $receipt = $this->receipt();

        // Module routes only mount on the boot AFTER install, so the controller
        // is invoked directly — the workaround the other export tests use.
        $response = (new LimoReceiptController())($receipt->id, app(LimoReceiptPdf::class));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString(
            'receipt-RCP-',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_the_document_carries_what_the_customer_needs_to_match_it(): void
    {
        $data = app(LimoReceiptPdf::class)->viewData($this->receipt());

        $this->assertSame('Qassim Makhlooq', $data['customerName']);
        // Without the booking the customer cannot tie the payment to a journey.
        $this->assertSame('BK/00007', $data['bookingReference']);
        $this->assertEqualsWithDelta(40.0, $data['amount'], 0.001);
        $this->assertEqualsWithDelta(20.0, $data['balance'], 0.001);
    }

    public function test_the_balance_is_read_from_the_receipt_not_recomputed(): void
    {
        $receipt = $this->receipt(amount: 40, balance: 20);

        // Money taken later must not rewrite what an older receipt said was
        // owed on the day it was written.
        $receipt->booking?->forceFill(['advance' => 60])->save();

        $this->assertEqualsWithDelta(
            20.0,
            app(LimoReceiptPdf::class)->viewData($receipt->fresh())['balance'],
            0.001,
        );
    }

    public function test_a_receipt_with_no_stored_balance_simply_omits_the_line(): void
    {
        // Older rows predate the column — better silent than a figure invented
        // from today's numbers.
        $data = app(LimoReceiptPdf::class)->viewData($this->receipt(balance: null));

        $this->assertNull($data['balance']);
    }

    public function test_the_row_does_not_open_an_editor(): void
    {
        $receipt = $this->receipt();

        // Pressing a receipt used to land on a form with an editable date. A
        // receipt records money already taken, so there is nothing to open it
        // for — the row offers Download and Send and nothing else.
        Livewire::test(Receipts::class)
            ->assertDontSeeHtml("window.location='" . url('/app/limousine/receipt/' . $receipt->id) . "'")
            ->assertSeeHtml(url('/app/limousine/receipt/' . $receipt->id . '/download'))
            ->assertSee(__('Send'));
    }

    public function test_sending_offers_the_customers_address_and_mails_the_pdf(): void
    {
        Mail::fake();
        $receipt = $this->receipt();
        $receipt->customer?->forceFill(['email' => 'qassim@example.test'])->save();

        Livewire::test(Receipts::class)
            ->call('openSend', $receipt->id)
            ->assertSet('sendEmail', 'qassim@example.test')
            ->call('sendReceipt')
            ->assertHasNoErrors()
            ->assertSet('sendingId', null);

        Mail::assertSent(ReceiptMail::class);
    }

    public function test_a_corrected_address_is_remembered_for_next_time(): void
    {
        Mail::fake();
        $receipt = $this->receipt(); // customer has no email on file

        Livewire::test(Receipts::class)
            ->call('openSend', $receipt->id)
            ->assertSet('sendEmail', '')
            ->set('sendEmail', 'accounts@company.test')
            ->call('sendReceipt')
            ->assertHasNoErrors();

        Mail::assertSent(ReceiptMail::class);
        $this->assertSame('accounts@company.test', $receipt->customer?->fresh()?->email);
    }

    public function test_sending_without_an_address_is_refused(): void
    {
        Mail::fake();
        $receipt = $this->receipt();

        Livewire::test(Receipts::class)
            ->call('openSend', $receipt->id)
            ->set('sendEmail', '')
            ->call('sendReceipt')
            ->assertHasErrors('sendEmail');

        Mail::assertNothingSent();
        unset($receipt);
    }
}

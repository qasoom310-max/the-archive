<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Livewire\Quotations;
use Modules\Limousine\Mail\QuotationMail;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\QuotationPdf;
use Tests\TestCase;

/**
 * What can be done to a quotation from the list: open it, send it, turn it into
 * a booking.
 *
 * Nothing DELETES one. A quote that went to a customer is a thing that happened,
 * whether or not it came to anything, and the price it named is the record of
 * what was agreed.
 */
final class LimoQuotationActionsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');
    }

    private function quote(?string $email = 'helen@example.com'): LimoQuotation
    {
        $customer = LimoCustomer::query()->create([
            'name' => 'Qassim Makhlooq',
            'phone' => '38467744',
            'email' => $email,
        ]);

        $quote = LimoQuotation::query()->create([
            'reference' => 'QT/00001',
            'customer_id' => $customer->id,
            'quote_date' => now(),
            'valid_until' => now()->addWeek(),
            'requested_by' => 'Office',
            'prepared_by' => 'Qassim',
            'status' => LimoQuotation::STATUS_DRAFT,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoQuotation::class,
            'legable_id' => $quote->id,
            'sequence' => 0,
            'reference' => '90001',
            'from_location' => 'Bahrain Airport',
            'to_location' => 'Manama',
            'start_at' => now()->addDays(3),
            'rate' => 45, 'net_amount' => 45,
        ]);

        $quote->recalcTotal();
        $quote->save();

        return $quote->refresh();
    }

    /* ── Parity with the booking form ────────────────────────────────────── */

    /**
     * "Prepared by" was an empty required box on a new quotation while the
     * booking form stamped it — the same sign-off, asked for two ways.
     */
    public function test_a_new_quotation_stamps_who_prepared_it(): void
    {
        Livewire::test(QuotationForm::class)
            ->assertSet('prepared_by', 'Qassim');
    }

    /** An existing quote keeps whoever raised it, not whoever opened it. */
    public function test_an_existing_quotation_keeps_its_own_preparer(): void
    {
        $quote = $this->quote();
        $quote->forceFill(['prepared_by' => 'Hussain'])->save();

        Livewire::test(QuotationForm::class, ['id' => $quote->id])
            ->assertSet('prepared_by', 'Hussain');
    }

    /* ── The three actions ───────────────────────────────────────────────── */

    public function test_the_list_offers_edit_send_and_process(): void
    {
        $this->quote();

        Livewire::test(Quotations::class)
            ->assertSee('Edit quotation')
            ->assertSee('Email the quotation to the customer')
            ->assertSee('Process into a booking');
    }

    public function test_processing_makes_a_booking_from_the_quotation(): void
    {
        $quote = $this->quote();

        Livewire::test(Quotations::class)
            ->call('process', $quote->id)
            ->assertRedirect();

        $booking = LimoBooking::query()->firstOrFail();
        $this->assertSame($quote->customer_id, $booking->customer_id);

        // The quote survives, marked as what it became.
        $fresh = $quote->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($booking->id, $fresh->booking_id);
    }

    /** Processing twice would put one job on the road under two references. */
    public function test_a_processed_quotation_is_not_offered_again(): void
    {
        $quote = $this->quote();

        Livewire::test(Quotations::class)->call('process', $quote->id);

        Livewire::test(Quotations::class)
            ->assertDontSee('Process into a booking')
            ->assertSee('Open the booking this became');

        $this->assertSame(1, LimoBooking::query()->count());
    }

    public function test_the_send_dialog_offers_the_customers_address(): void
    {
        $quote = $this->quote();

        Livewire::test(Quotations::class)
            ->call('openSend', $quote->id)
            ->assertSet('sendingId', $quote->id)
            ->assertSet('sendEmail', 'helen@example.com');
    }

    public function test_sending_mails_the_quotation_and_records_it(): void
    {
        Mail::fake();
        $quote = $this->quote();

        Livewire::test(Quotations::class)
            ->call('openSend', $quote->id)
            ->call('sendQuotation')
            ->assertHasNoErrors()
            ->assertSet('sendingId', null);

        Mail::assertSent(QuotationMail::class, fn ($mail): bool => $mail->hasTo('helen@example.com'));

        $fresh = $quote->fresh();
        $this->assertNotNull($fresh?->sent_at);
        $this->assertSame('helen@example.com', $fresh?->sent_to);
        // A quote that has gone out is no longer a draft.
        $this->assertSame(LimoQuotation::STATUS_SENT, $fresh?->status);
    }

    /** Re-sending an answered quote must not walk it back to "waiting". */
    public function test_resending_does_not_undo_an_answer(): void
    {
        Mail::fake();
        $quote = $this->quote();
        $quote->forceFill(['status' => LimoQuotation::STATUS_ACCEPTED])->save();

        Livewire::test(Quotations::class)
            ->call('openSend', $quote->id)
            ->call('sendQuotation')
            ->assertHasNoErrors();

        $this->assertSame(LimoQuotation::STATUS_ACCEPTED, $quote->fresh()?->status);
    }

    public function test_a_bad_address_is_refused(): void
    {
        Mail::fake();
        $quote = $this->quote();

        Livewire::test(Quotations::class)
            ->call('openSend', $quote->id)
            ->set('sendEmail', 'not-an-address')
            ->call('sendQuotation')
            ->assertHasErrors('sendEmail');

        Mail::assertNothingSent();
        $this->assertNull($quote->fresh()?->sent_at);
    }

    /* ── The document ────────────────────────────────────────────────────── */

    public function test_the_pdf_lists_every_trip_and_the_total(): void
    {
        $quote = $this->quote();

        $data = app(QuotationPdf::class)->viewData($quote);

        $this->assertSame('QT/00001', $data['reference']);
        $this->assertSame('Qassim Makhlooq', $data['customerName']);
        $this->assertCount(1, $data['legs']);
        $this->assertSame(45.0, $data['total']);

        $this->assertStringStartsWith('%PDF-', app(QuotationPdf::class)->render($quote));
    }

    /* ── The rule that has no button ─────────────────────────────────────── */

    /**
     * No delete, anywhere. Asserted rather than assumed, so adding one later is
     * a deliberate act that has to argue with this test first.
     */
    public function test_nothing_deletes_a_quotation(): void
    {
        $quote = $this->quote();

        foreach (['delete', 'destroy', 'remove'] as $method) {
            $this->assertFalse(
                method_exists(Quotations::class, $method),
                "Quotations::{$method}() exists — quotations are not deletable.",
            );
            $this->assertFalse(
                method_exists(QuotationForm::class, $method),
                "QuotationForm::{$method}() exists — quotations are not deletable.",
            );
        }

        Livewire::test(Quotations::class)->assertDontSee('Delete');

        $this->assertSame(1, LimoQuotation::query()->whereKey($quote->id)->count());
    }
}

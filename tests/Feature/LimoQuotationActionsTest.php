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
use Modules\Limousine\Models\LimoInvoice;
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

    /* ── How long the quote stands ───────────────────────────────────────── */

    /**
     * The office decides "a month", not "07-Oct-2026" — so the periods are the
     * buttons and the date follows them, with a date picker for the customer
     * who asks for a particular day.
     */
    public function test_a_period_sets_the_date_it_comes_to(): void
    {
        $form = Livewire::test(QuotationForm::class)
            ->set('quote_date', '2026-08-31')
            // A week is the default a new quote opens on.
            ->assertSet('validity', 'week');

        $form->call('setValidity', 'month')
            ->assertSet('validity', 'month')
            ->assertSet('valid_until', '2026-09-30');

        $form->call('setValidity', 'year')
            ->assertSet('validity', 'year')
            ->assertSet('valid_until', '2027-08-31');

        $form->call('setValidity', 'week')
            ->assertSet('valid_until', '2026-09-07');
    }

    /** Measured from the QUOTE's date, not from whenever it is opened. */
    public function test_a_period_re_measures_when_the_quote_date_moves(): void
    {
        Livewire::test(QuotationForm::class)
            ->call('setValidity', 'month')
            ->set('quote_date', '2026-01-15')
            ->assertSet('valid_until', '2026-02-15');
    }

    /** Picking a date by hand is the custom case, and the period lets go. */
    public function test_choosing_a_date_by_hand_is_custom(): void
    {
        Livewire::test(QuotationForm::class)
            ->set('quote_date', '2026-08-31')
            ->call('setValidity', 'month')
            ->set('valid_until', '2026-12-25')
            ->assertSet('validity', 'custom')
            // And it stays put when the quote date moves.
            ->set('quote_date', '2026-09-01')
            ->assertSet('valid_until', '2026-12-25');
    }

    /** Re-opening a quote shows the button that was pressed, not "custom". */
    public function test_an_existing_quote_shows_the_period_it_was_written_with(): void
    {
        $quote = $this->quote();
        $quote->forceFill([
            'quote_date' => '2026-08-31',
            'valid_until' => '2027-08-31',
        ])->save();

        Livewire::test(QuotationForm::class, ['id' => $quote->id])
            ->assertSet('validity', 'year');
    }

    /* ── The three actions ───────────────────────────────────────────────── */

    public function test_the_list_offers_edit_send_and_process(): void
    {
        $this->quote();

        Livewire::test(Quotations::class)
            ->assertSee('Edit quotation')
            ->assertSee('Email the quotation to the customer')
            ->assertSee('Raise invoice');
    }

    public function test_processing_raises_an_invoice_and_the_trip_comes_from_it(): void
    {
        $quote = $this->quote();

        // Quote → invoice. The journey is dispatched from the bill, not instead
        // of it: the customer agrees a price, we bill it, then it runs.
        Livewire::test(Quotations::class)
            ->call('process', $quote->id)
            ->assertRedirect();

        $invoice = LimoInvoice::query()->firstOrFail();
        $this->assertSame($quote->customer_id, $invoice->customer_id);
        $this->assertSame($quote->id, $invoice->quotation_id);
        $this->assertSame(0, LimoBooking::query()->count(), 'no trip until the invoice makes one');

        // …and the invoice is what creates it, carrying the quote's legs.
        $booking = $invoice->createTrip();
        $this->assertNotNull($booking);
        $this->assertSame($quote->customer_id, $booking->customer_id);
        $this->assertSame($booking->id, $invoice->fresh()?->booking_id);
        $this->assertSame($booking->id, $quote->fresh()?->booking_id);
    }

    /** Processing twice would put one job on the road under two references. */
    public function test_a_processed_quotation_is_not_offered_again(): void
    {
        $quote = $this->quote();

        Livewire::test(Quotations::class)->call('process', $quote->id);

        // Billed but not yet dispatched: booking_id is still null, so the
        // INVOICE is what has to stop it being offered a second time.
        Livewire::test(Quotations::class)
            ->assertDontSee('Raise invoice')
            ->assertSee('Download the invoice this became');

        $this->assertSame(1, LimoInvoice::query()->count());
        $this->assertSame(0, LimoBooking::query()->count());
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

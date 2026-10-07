<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\InvoiceForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Models\LimoReceipt;
use Tests\TestCase;

/**
 * Quote → invoice → trip → receipt.
 *
 * Every trip is billed, and it is billed by existing rather than by somebody
 * remembering to press a button. The invoice follows the job's price for as
 * long as the job exists, paid or not: a booking has exactly one invoice, so an
 * invoice left on an old price could never be corrected.
 */
final class LimoInvoiceChainTest extends TestCase
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

    /** A booking written through the form, the way the office makes one. */
    private function bookThrough(float $rate, float $advance = 0): LimoBooking
    {
        Livewire::test(BookingForm::class)
            ->set('customer_id', $this->customer()->id)
            ->set('pax_name', 'Helen')
            ->set('requested_by', 'Sara')
            ->set('payment_method', 'cash')
            ->set('advance', $advance)
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'Hotel')
            ->set('legs.0.start_at', '2026-09-05T09:00')
            ->set('legs.0.rate', $rate)
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors();

        return LimoBooking::query()->latest('id')->firstOrFail();
    }

    public function test_a_new_booking_issues_its_invoice_without_being_asked(): void
    {
        $booking = $this->bookThrough(rate: 45);

        $invoice = LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertEqualsWithDelta(45.0, $invoice->total, 0.001);
        $this->assertSame(LimoInvoice::STATUS_UNPAID, $invoice->status);
    }

    public function test_the_invoice_follows_the_trip_while_nothing_is_paid(): void
    {
        $booking = $this->bookThrough(rate: 45);

        // The job is re-priced before any money changes hands.
        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->set('legs.0.rate', 60)
            ->set('legs.0.car_details', 'Sedan')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(
            60.0,
            LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail()->total,
            0.001,
        );
    }

    /**
     * The office's report: a booking re-priced after its deposit kept an
     * invoice for the old amount, and no second invoice could be raised.
     */
    public function test_a_part_paid_invoice_still_follows_the_trip(): void
    {
        $booking = $this->bookThrough(rate: 45, advance: 20);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->set('legs.0.rate', 90)
            ->set('legs.0.car_details', 'Sedan')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = LimoInvoice::query()->where('booking_id', $booking->id)->sole();
        $this->assertEqualsWithDelta(90.0, $invoice->total, 0.001);
        $this->assertEqualsWithDelta(20.0, $invoice->amount_paid, 0.001);
        $this->assertSame(LimoInvoice::STATUS_PARTIAL, $invoice->status);
    }

    /** Paid in full, then a trip is added: the invoice and the booking both owe again. */
    public function test_a_paid_invoice_raised_in_price_owes_the_difference(): void
    {
        $booking = $this->bookThrough(rate: 45, advance: 45);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->set('legs.0.rate', 60)
            ->set('legs.0.car_details', 'Sedan')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = LimoInvoice::query()->where('booking_id', $booking->id)->sole();
        $this->assertEqualsWithDelta(60.0, $invoice->total, 0.001);
        $this->assertSame(LimoInvoice::STATUS_PARTIAL, $invoice->status);
        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $booking->fresh()?->payment_status);
    }

    /** The deploy repair: an invoice left on the old price is put right. */
    public function test_the_repair_corrects_an_invoice_left_on_an_old_price(): void
    {
        $booking = $this->bookThrough(rate: 45, advance: 20);
        $invoice = LimoInvoice::query()->where('booking_id', $booking->id)->sole();
        // As the old rule left it: the booking re-priced, the invoice not.
        $booking->forceFill(['fare' => 70, 'amount' => 70])->saveQuietly();
        $invoice->forceFill(['total' => 45, 'subtotal' => 45])->saveQuietly();

        $migration = require base_path('Modules/Limousine/database/migrations/2026_10_07_950044_bring_paid_invoices_back_in_step_with_their_booking.php');
        $migration->up();

        $this->assertEqualsWithDelta(70.0, $invoice->fresh()?->total, 0.001);
        $this->assertSame(LimoInvoice::STATUS_PARTIAL, $invoice->fresh()?->status);
    }

    /** An old-system booking's invoice keeps the old system's figure. */
    public function test_the_repair_leaves_imported_bookings_alone(): void
    {
        $booking = $this->bookThrough(rate: 45);
        $invoice = LimoInvoice::query()->where('booking_id', $booking->id)->sole();
        $booking->forceFill(['fare' => 70, 'imported_at' => now()])->saveQuietly();

        $migration = require base_path('Modules/Limousine/database/migrations/2026_10_07_950044_bring_paid_invoices_back_in_step_with_their_booking.php');
        $migration->up();

        $this->assertEqualsWithDelta(45.0, $invoice->fresh()?->total, 0.001);
    }

    public function test_money_taken_on_the_booking_settles_its_invoice(): void
    {
        $booking = $this->bookThrough(rate: 45, advance: 45);

        $invoice = LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(LimoInvoice::STATUS_PAID, $invoice->status);
        $this->assertEqualsWithDelta(45.0, $invoice->amount_paid, 0.001);

        // And the receipt names both: the job it belongs to and the bill it
        // settles.
        $receipt = LimoReceipt::query()->firstOrFail();
        $this->assertSame($booking->id, $receipt->booking_id);
        $this->assertSame($invoice->id, $receipt->invoice_id);
    }

    public function test_the_trip_created_from_an_invoice_carries_the_quotes_legs(): void
    {
        $quote = LimoQuotation::query()->create([
            'customer_id' => $this->customer()->id, 'fare' => 30,
        ]);
        $quote->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Airport',
            'to_location' => 'Hotel', 'start_at' => now(), 'days' => 1,
            'rate' => 30, 'rate_basis' => 'trip', 'net_amount' => 30,
        ]);

        $booking = $quote->convertToInvoice()->createTrip();

        $this->assertNotNull($booking);
        $this->assertSame('Airport', $booking->legs->first()?->from_location);
        // A booked trip starts in the queue, waiting to be dispatched.
        $this->assertSame(LimoLeg::STATUS_QUEUE, $booking->legs->first()?->status);
    }

    public function test_creating_the_trip_twice_does_not_raise_a_second_one(): void
    {
        $quote = LimoQuotation::query()->create([
            'customer_id' => $this->customer()->id, 'fare' => 30,
        ]);
        $invoice = $quote->convertToInvoice();

        $first = $invoice->createTrip();
        $again = $invoice->fresh()?->createTrip();

        $this->assertSame($first?->id, $again?->id);
        $this->assertSame(1, LimoBooking::query()->count());
        // And no second invoice either — the booking's own sync finds this one.
        $this->assertSame(1, LimoInvoice::query()->count());
    }

    /** A quote sitting on file, ready to be billed. */
    private function quoteFor(LimoCustomer $customer, float $fare = 30): LimoQuotation
    {
        return LimoQuotation::query()->create([
            'customer_id' => $customer->id, 'fare' => $fare,
        ]);
    }

    public function test_the_new_invoice_screen_offers_the_customers_quotes(): void
    {
        $customer = $this->customer();
        $mine = $this->quoteFor($customer);
        $theirs = $this->quoteFor(LimoCustomer::query()->create(['name' => 'Someone Else']));

        // A bill is raised from a price already agreed, so the screen summons a
        // quote instead of asking the office to retype totals it has on file.
        Livewire::test(InvoiceForm::class)
            ->set('customer_id', $customer->id)
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference);
    }

    public function test_a_quote_already_billed_is_not_offered_again(): void
    {
        $customer = $this->customer();
        $quote = $this->quoteFor($customer);
        $quote->convertToInvoice();

        Livewire::test(InvoiceForm::class)
            ->set('customer_id', $customer->id)
            ->assertDontSee($quote->reference);
    }

    public function test_a_quote_can_be_found_by_its_number_alone(): void
    {
        $quote = $this->quoteFor($this->customer());

        // Searching a number is how the office finds a quote when it does not
        // remember whose it is — so picking one fills the customer in.
        Livewire::test(InvoiceForm::class)
            ->set('quoteSearch', (string) $quote->reference)
            ->assertSee($quote->reference)
            ->call('selectQuote', $quote->id)
            ->assertSet('customer_id', $quote->customer_id);
    }

    public function test_issuing_from_the_picker_raises_the_quotes_invoice(): void
    {
        $quote = $this->quoteFor($this->customer(), fare: 55);

        Livewire::test(InvoiceForm::class)
            ->call('selectQuote', $quote->id)
            ->call('issueInvoice')
            ->assertHasNoErrors();

        $invoice = LimoInvoice::query()->where('quotation_id', $quote->id)->firstOrFail();
        $this->assertEqualsWithDelta(55.0, $invoice->total, 0.001);
        $this->assertSame(LimoQuotation::STATUS_ACCEPTED, $quote->fresh()?->status);
    }

    public function test_a_declined_quote_is_refused_even_when_asked_for_directly(): void
    {
        $quote = $this->quoteFor($this->customer());
        $quote->forceFill(['status' => LimoQuotation::STATUS_DECLINED])->save();

        // The picker is a convenience, not the rule — a crafted call meets the
        // same check the list is drawn from.
        Livewire::test(InvoiceForm::class)
            ->call('selectQuote', $quote->id)
            ->assertHasErrors('quotation_id');

        $this->assertSame(0, LimoInvoice::query()->count());
    }

    public function test_a_blank_form_cannot_conjure_an_invoice(): void
    {
        // There is no create-from-nothing any more: an invoice is raised from a
        // quotation or issued with a booking. Livewire dispatches straight to
        // methods, so a stale page reaching save() has to be refused.
        Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer()->id)
            ->set('subtotal', '99')
            ->call('save')
            ->assertHasErrors('quotation_id');

        $this->assertSame(0, LimoInvoice::query()->count());
    }

    public function test_bookings_already_on_file_are_backfilled_with_invoices(): void
    {
        $customer = $this->customer();

        $paid = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'fare' => 50, 'advance' => 50,
            'status' => LimoBooking::STATUS_COMPLETED,
        ]);
        $part = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'fare' => 80, 'advance' => 30,
            'status' => LimoBooking::STATUS_CONFIRMED,
        ]);

        $migration = require __DIR__ . '/../../Modules/Limousine/database/migrations/2026_09_01_950027_backfill_invoices_for_bookings.php';
        $migration->up();

        // Money already received is matched on, so a trip that was paid for
        // reads as paid rather than as a fresh debt.
        $paidInvoice = LimoInvoice::query()->where('booking_id', $paid->id)->firstOrFail();
        $this->assertSame(LimoInvoice::STATUS_PAID, $paidInvoice->status);
        $this->assertNotNull($paidInvoice->reference);

        $partInvoice = LimoInvoice::query()->where('booking_id', $part->id)->firstOrFail();
        $this->assertSame(LimoInvoice::STATUS_PARTIAL, $partInvoice->status);
        $this->assertEqualsWithDelta(30.0, $partInvoice->amount_paid, 0.001);
    }

    public function test_the_backfill_leaves_an_already_invoiced_booking_alone(): void
    {
        $booking = $this->bookThrough(rate: 45);
        $before = LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail();

        $migration = require __DIR__ . '/../../Modules/Limousine/database/migrations/2026_09_01_950027_backfill_invoices_for_bookings.php';
        $migration->up();

        $this->assertSame(1, LimoInvoice::query()->where('booking_id', $booking->id)->count());
        $this->assertSame($before->id, LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail()->id);
    }
}

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
 * remembering to press a button. The invoice follows the job while the price is
 * still being settled and stops the moment money lands, because a document
 * somebody holds a receipt against must not re-price itself afterwards.
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
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(
            60.0,
            LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail()->total,
            0.001,
        );
    }

    public function test_once_money_lands_the_invoice_stops_following(): void
    {
        $booking = $this->bookThrough(rate: 45, advance: 20);

        $invoice = LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertTrue($invoice->isFrozen(), 'a part payment freezes the document');

        // Re-pricing the trip afterwards must NOT rewrite a bill somebody holds
        // a receipt against.
        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->set('legs.0.rate', 90)
            ->call('save')
            ->assertHasNoErrors();

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

    public function test_payment_can_be_taken_on_the_invoice_itself(): void
    {
        $booking = $this->bookThrough(rate: 45);
        $invoice = LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail();

        Livewire::test(InvoiceForm::class, ['id' => $invoice->id])
            ->call('openCollect')
            // Offered as the whole balance, which is what is being asked for.
            ->assertSet('collectAmount', '45')
            ->call('saveCollect')
            ->assertHasNoErrors();

        $fresh = $invoice->fresh();
        $this->assertSame(LimoInvoice::STATUS_PAID, $fresh?->status);
        // One truth: the same receipt the counter would have written.
        $this->assertSame(1, LimoReceipt::query()->count());
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->fresh()?->payment_status);
    }

    public function test_an_invoice_with_no_trip_behind_it_cannot_take_money(): void
    {
        $quote = LimoQuotation::query()->create([
            'customer_id' => $this->customer()->id, 'fare' => 30,
        ]);
        $invoice = $quote->convertToInvoice();

        // A receipt belongs to a job. Billing first is fine; taking money for a
        // journey that does not exist yet is not.
        Livewire::test(InvoiceForm::class, ['id' => $invoice->id])
            ->call('openCollect')
            ->call('saveCollect')
            ->assertHasErrors('collectAmount');

        $this->assertSame(0, LimoReceipt::query()->count());
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

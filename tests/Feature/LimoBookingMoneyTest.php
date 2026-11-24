<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\BookingPayments;
use Modules\Limousine\Services\CouponRedeemer;
use Modules\Limousine\Services\LimoQueueRows;
use Modules\Limousine\Support\BookingMoney;
use Tests\TestCase;

/**
 * The "Money on this booking" box: the trips that make the total and every
 * payment against it, so an unexpected balance can be traced.
 */
final class LimoBookingMoneyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /** @param list<array{0: float, 1: string, 2?: ?string}> $trips */
    private function booking(array $trips): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => 'Lily Demo']);
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_QUEUE,
            'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);
        foreach ($trips as $i => $trip) {
            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => $i,
                'status' => $trip[1], 'refund_outcome' => $trip[2] ?? null, 'start_at' => now()->addDay(),
                'from_location' => 'Khobar', 'to_location' => 'Bahrain Airport',
                'rate' => $trip[0], 'net_amount' => $trip[0],
            ]);
        }
        $booking->recalcTotal();
        $booking->save();

        return $booking->refresh();
    }

    public function test_it_lists_the_trips_that_make_the_total_and_the_payments(): void
    {
        $booking = $this->booking([[280.0, LimoLeg::STATUS_QUEUE], [540.0, LimoLeg::STATUS_QUEUE], [100.0, LimoLeg::STATUS_CANCELLED, LimoLeg::REFUND_NONE]]);
        app(BookingPayments::class)->receive($booking, 680.0, 'cash');

        $money = BookingMoney::of($booking->refresh());

        $this->assertCount(3, $money['trips']);
        $this->assertSame([true, true, false], array_column($money['trips'], 'billed'));
        $this->assertEqualsWithDelta(820.0, $money['total'], 0.001);
        $this->assertCount(1, $money['receipts']);
        $this->assertTrue($money['receipts'][0]['through_booking']);
        $this->assertEqualsWithDelta(680.0, $money['received'], 0.001);
        $this->assertEqualsWithDelta(140.0, $money['owed'], 0.001);
        $this->assertFalse($money['disagrees']);
    }

    /**
     * The bug: 680 paid against the invoice (Receipts screen, account payment)
     * and 140 with a coupon. The coupon only reaches the advance, the receipt
     * only the invoice, and "Received" took the larger of the two: 680, so the
     * coupon read as still owed on a booking paid in full.
     */
    public function test_invoice_payment_plus_coupon_reads_paid_in_full(): void
    {
        $booking = $this->booking([[820.0, LimoLeg::STATUS_QUEUE]]);
        $invoice = $booking->syncInvoice();
        LimoReceipt::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $booking->customer_id,
            'date' => now(), 'amount' => 680.0, 'method' => 'transfer',
        ]);
        $invoice->refresh()->recomputePaid();

        $coupon = LimoCoupon::query()->create([
            'code' => 'CPN-TEST', 'customer_id' => $booking->customer_id, 'amount' => 140.0,
            'expires_at' => now()->addYear(),
        ]);
        $result = app(CouponRedeemer::class)->apply($coupon->code, $booking->refresh());
        $this->assertTrue($result['ok']);

        $booking->refresh();
        $this->assertEqualsWithDelta(820.0, $booking->amountReceived(), 0.001);
        $this->assertEqualsWithDelta(0.0, $booking->amountOwed(), 0.001);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->paymentState());

        // The list reads it the same way (loaded sums, and the Unpaid tab's SQL).
        $row = collect(app(LimoQueueRows::class)->all('all', '', ''))->firstWhere('booking_id', $booking->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.0, $row['balance'], 0.001);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $row['payment']);
        $this->assertFalse(LimoBooking::query()->owing()->whereKey($booking->id)->exists());

        $money = BookingMoney::of($booking);
        $this->assertFalse($money['disagrees']);
        $this->assertFalse($money['receipts'][0]['through_booking']);
    }

    public function test_a_refund_is_not_counted_as_money_held(): void
    {
        $booking = $this->booking([[300.0, LimoLeg::STATUS_QUEUE], [200.0, LimoLeg::STATUS_QUEUE]]);
        app(BookingPayments::class)->receive($booking, 500.0, 'cash');
        $leg = $booking->legs()->orderBy('sequence')->get()->last();
        $this->assertNotNull($leg);
        $leg->forceFill(['status' => LimoLeg::STATUS_CANCELLED, 'refund_outcome' => LimoLeg::REFUND_REFUNDED, 'refund_amount' => 200.0])->saveQuietly();
        $booking->refresh();
        $booking->advance = 300.0;
        $booking->recalcTotal();
        $booking->saveQuietly();

        $booking->refresh();
        $this->assertEqualsWithDelta(300.0, $booking->amountReceived(), 0.001);
        $this->assertEqualsWithDelta(0.0, $booking->amountOwed(), 0.001);
    }

    public function test_a_receipt_that_reached_neither_figure_is_flagged(): void
    {
        $booking = $this->booking([[400.0, LimoLeg::STATUS_QUEUE]]);
        app(BookingPayments::class)->receive($booking, 300.0, 'cash');
        // Written against the booking alone: neither the advance nor the
        // invoice counts it.
        LimoReceipt::query()->create([
            'booking_id' => $booking->id, 'customer_id' => $booking->customer_id,
            'date' => now(), 'amount' => 100.0, 'method' => 'cash',
        ]);

        $money = BookingMoney::of($booking->refresh());

        $this->assertEqualsWithDelta(400.0, $money['receipts_total'], 0.001);
        $this->assertEqualsWithDelta(300.0, $money['received'], 0.001);
        $this->assertTrue($money['disagrees']);
    }

    public function test_an_unpaid_payment_link_is_pointed_out(): void
    {
        $booking = $this->booking([[600.0, LimoLeg::STATUS_QUEUE]]);
        LimoPaymentLink::query()->create([
            'booking_id' => $booking->id, 'amount' => 140.0, 'currency' => 'BHD',
            'status' => LimoPaymentLink::STATUS_PENDING,
        ]);
        LimoPaymentLink::query()->create([
            'booking_id' => $booking->id, 'amount' => 200.0, 'currency' => 'BHD',
            'status' => LimoPaymentLink::STATUS_PAID, 'paid_at' => now(),
        ]);

        $money = BookingMoney::of($booking);

        $this->assertCount(2, $money['links']);
        $this->assertEqualsWithDelta(140.0, $money['links_waiting'], 0.001);
    }

    public function test_the_booking_page_shows_the_box(): void
    {
        $booking = $this->booking([[280.0, LimoLeg::STATUS_QUEUE]]);
        app(BookingPayments::class)->receive($booking, 100.0, 'cash');

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->assertSeeHtml('data-booking-money')
            ->assertSee('Money on this booking')
            ->assertSeeHtml('data-money-receipt')
            ->assertSeeHtml('data-money-trip');
    }

    public function test_a_new_booking_has_no_box(): void
    {
        Livewire::test(BookingForm::class)->assertDontSeeHtml('data-booking-money');
    }
}

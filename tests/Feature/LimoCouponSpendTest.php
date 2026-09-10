<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Coupons;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\CouponRedeemer;
use Modules\Rental\Models\RentalOrder;
use Tests\TestCase;

/**
 * Spending credit — on a limousine trip, or on a rental car.
 *
 * The credit is issued by the limousine side, but it is the CUSTOMER's money:
 * somebody owed for a journey that never happened may well want a car with it.
 * The customer does not see two businesses, so a coupon does not either.
 */
final class LimoCouponSpendTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('rental');
    }

    private function coupon(float $amount = 63): LimoCoupon
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '+973 1234']);

        return LimoCoupon::query()->create([
            'code' => 'CPN-0002',
            'customer_id' => $customer->id,
            'leg_reference' => '10006',
            'amount' => $amount,
            'expires_at' => now()->addMonths(12),
        ]);
    }

    private function limoBooking(float $fare): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00050',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '10050',
            'status' => LimoLeg::STATUS_QUEUE,
            'start_at' => now()->addDay(),
            'from_location' => 'Hotel',
            'to_location' => 'Airport',
            'rate' => $fare, 'net_amount' => $fare,
        ]);

        $booking->recalcTotal();
        $booking->save();

        return $booking->refresh();
    }

    /**
     * A rental order priced the way a real one is — rate × days — because
     * applying credit re-runs recalcTotals(), and a total pinned by hand would
     * simply be recomputed away.
     */
    private function rentalOrder(float $total): RentalOrder
    {
        $days = 4;

        $order = new RentalOrder();
        $order->forceFill([
            'reference' => 'RO/00019',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays($days)->toDateString(),
            'rate_type' => 'daily',
            'rate' => $total / $days,
            'vat_rate' => 0,
            'advance_amount' => 0,
        ]);
        $order->recalcTotals();
        $order->save();

        $this->assertSame($total, $order->refresh()->total, 'Fixture did not price as expected.');

        return $order;
    }

    /* ── The limousine side, unchanged by the generalisation ─────────────── */

    public function test_credit_comes_off_a_limousine_booking(): void
    {
        $coupon = $this->coupon(63);
        $booking = $this->limoBooking(100);

        $result = app(CouponRedeemer::class)->apply('CPN-0002', $booking);

        $this->assertTrue($result['ok']);
        $this->assertSame(63.0, $result['applied']);
        $this->assertSame(37.0, $booking->fresh()?->balanceDue());
        $this->assertSame(0.0, $coupon->fresh()?->remaining());
    }

    /* ── The rental side, which is the new part ──────────────────────────── */

    public function test_credit_comes_off_a_rental_order(): void
    {
        $coupon = $this->coupon(63);
        $order = $this->rentalOrder(100);

        $result = app(CouponRedeemer::class)->apply('CPN-0002', $order);

        $this->assertTrue($result['ok']);
        $this->assertSame(63.0, $result['applied']);

        $fresh = $order->fresh();
        $this->assertSame(63.0, $fresh?->advance_amount);
        // The rental's own balance and payment state follow, exactly as they
        // would for cash over the counter.
        $this->assertSame(37.0, $fresh?->balance);
        $this->assertSame(RentalOrder::PAYMENT_PARTIAL, $fresh?->payment_status);
        $this->assertSame(0.0, $coupon->fresh()?->remaining());
    }

    public function test_a_rental_covered_in_full_reads_as_paid(): void
    {
        $this->coupon(200);
        $order = $this->rentalOrder(100);

        app(CouponRedeemer::class)->apply('CPN-0002', $order);

        $this->assertSame(RentalOrder::PAYMENT_PAID, $order->fresh()?->payment_status);
        // Only what was needed came off; the rest waits for next time.
        $this->assertSame(100.0, LimoCoupon::query()->first()?->remaining());
    }

    /** The history says what the credit went on, whichever business it was. */
    public function test_a_redemption_records_what_it_was_spent_on(): void
    {
        $coupon = $this->coupon(63);
        $order = $this->rentalOrder(100);

        app(CouponRedeemer::class)->apply('CPN-0002', $order);

        $redemption = $coupon->fresh()?->redemptions->first();
        $this->assertNotNull($redemption);
        $this->assertSame(RentalOrder::class, $redemption->redeemable_type);
        $this->assertSame($order->id, $redemption->redeemable_id);
        $this->assertSame('RO/00019', $redemption->booking_reference);
        // Not a limousine booking, so the old column stays empty rather than
        // pointing at an id from another table.
        $this->assertNull($redemption->limo_booking_id);
        $this->assertInstanceOf(RentalOrder::class, $redemption->redeemable);
    }

    public function test_a_limousine_redemption_still_fills_the_old_column(): void
    {
        $coupon = $this->coupon(63);
        $booking = $this->limoBooking(100);

        app(CouponRedeemer::class)->apply('CPN-0002', $booking);

        $redemption = $coupon->fresh()?->redemptions->first();
        $this->assertSame($booking->id, $redemption?->limo_booking_id);
        $this->assertSame(LimoBooking::class, $redemption?->redeemable_type);
    }

    public function test_nothing_owed_means_nothing_to_spend_it_on(): void
    {
        $this->coupon(63);
        $order = $this->rentalOrder(0);

        $result = app(CouponRedeemer::class)->apply('CPN-0002', $order);

        $this->assertFalse($result['ok']);
        $this->assertSame(CouponRedeemer::ERROR_NOTHING_DUE, $result['error']);
    }

    /* ── Getting there from the coupons page ─────────────────────────────── */

    public function test_the_use_dialog_offers_both_businesses(): void
    {
        $coupon = $this->coupon();

        Livewire::test(Coupons::class)
            ->call('openUse', $coupon->id)
            ->assertSet('usingId', $coupon->id)
            ->assertSee('Limousine trip')
            ->assertSee('Rental car');
    }

    public function test_choosing_a_trip_opens_the_booking_form_with_the_code(): void
    {
        $coupon = $this->coupon();

        Livewire::test(Coupons::class)
            ->call('openUse', $coupon->id)
            ->call('useFor', 'limousine')
            // Code AND customer travel, so neither is retyped and a mistyped
            // code cannot lose the customer their credit.
            ->assertRedirect('/app/limousine/booking/new?coupon=CPN-0002&customer=' . $coupon->customer_id);
    }

    public function test_choosing_a_car_opens_the_rental_form_with_the_code(): void
    {
        $coupon = $this->coupon();

        Livewire::test(Coupons::class)
            ->call('openUse', $coupon->id)
            ->call('useFor', 'rental')
            ->assertRedirect('/app/rental/order/new?coupon=CPN-0002&customer=' . $coupon->customer_id);
    }

    /** A spent or expired coupon has nothing to put towards anything. */
    public function test_an_empty_coupon_cannot_be_used(): void
    {
        $coupon = $this->coupon(63);
        $booking = $this->limoBooking(100);
        app(CouponRedeemer::class)->apply('CPN-0002', $booking);

        Livewire::test(Coupons::class)
            ->call('openUse', $coupon->id)
            ->assertSet('usingId', null);
    }

    public function test_an_expired_coupon_cannot_be_used(): void
    {
        $coupon = $this->coupon();
        $coupon->forceFill(['expires_at' => now()->subDay()])->save();

        Livewire::test(Coupons::class)
            ->call('openUse', $coupon->id)
            ->assertSet('usingId', null);
    }
}

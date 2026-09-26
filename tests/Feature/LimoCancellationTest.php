<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\CouponRedeemer;
use Modules\Limousine\Services\TripCancellation;
use Tests\TestCase;

/**
 * Cancelling a trip, and the credit it can leave behind.
 *
 * The rule: nothing paid cancels cleanly; paid with more than 48 hours to go
 * earns a full refund; paid inside 48 hours earns no refund but a coupon for
 * what was paid, good for a year. Credit is then spent in pieces across future
 * bookings until it runs out.
 */
final class LimoCancellationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function trip(float $amount, string $startsIn, bool $paid): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/' . fake()->unique()->numberBetween(1000, 9999),
            'customer_id' => $customer->id,
            'pickup_at' => now()->add($startsIn),
            'status' => LimoBooking::STATUS_ACTIVE,
            'advance' => $paid ? $amount : 0,
            'payment_status' => $paid ? LimoBooking::PAYMENT_PAID : LimoBooking::PAYMENT_UNPAID,
        ]);

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'status' => LimoLeg::STATUS_ACTIVE,
            'start_at' => now()->add($startsIn),
            'from_location' => 'Airport',
            'rate' => $amount, 'net_amount' => $amount,
        ]);

        return $leg->refresh();
    }

    public function test_an_unpaid_trip_just_cancels(): void
    {
        $leg = $this->trip(25, '3 days', paid: false);

        $result = app(TripCancellation::class)->cancel($leg, 'customer changed plans');

        $this->assertSame(TripCancellation::OUTCOME_NONE, $result['outcome']);
        $this->assertSame(LimoLeg::STATUS_CANCELLED, $leg->fresh()?->status);
        $this->assertSame(0, LimoCoupon::query()->count());
    }

    public function test_more_than_48_hours_before_the_trip_a_full_refund_is_due(): void
    {
        $leg = $this->trip(25, '5 days', paid: true);

        $result = app(TripCancellation::class)->cancel($leg);

        $this->assertSame(TripCancellation::OUTCOME_REFUNDED, $result['outcome']);
        $this->assertSame(25.0, $result['amount']);
        // Money goes back, so no credit is created.
        $this->assertSame(0, LimoCoupon::query()->count());
    }

    public function test_a_due_refund_can_be_given_as_credit_instead(): void
    {
        $leg = $this->trip(25, '5 days', paid: true);

        $result = app(TripCancellation::class)->cancel($leg, null, refundAsCoupon: true);

        $this->assertSame(TripCancellation::OUTCOME_COUPON, $result['outcome']);
        $this->assertSame(25.0, $result['coupon']?->amount);
    }

    /** The rule that costs the customer: inside the window, no money back. */
    public function test_within_48_hours_there_is_no_refund_but_a_coupon_is_issued(): void
    {
        $leg = $this->trip(25, '10 hours', paid: true);

        $result = app(TripCancellation::class)->cancel($leg, 'late cancellation');

        $this->assertSame(TripCancellation::OUTCOME_COUPON, $result['outcome']);

        $coupon = $result['coupon'];
        $this->assertNotNull($coupon);
        $this->assertSame(25.0, $coupon->amount);
        $this->assertSame(25.0, $coupon->remaining());
        // Good for a year from the booking, not from the cancellation.
        $this->assertTrue($coupon->expires_at?->isAfter(now()->addMonths(11)) ?? false);
    }

    public function test_a_trip_already_past_its_time_is_inside_the_window(): void
    {
        $leg = $this->trip(25, '-2 hours', paid: true);

        $result = app(TripCancellation::class)->cancel($leg);

        $this->assertSame(TripCancellation::OUTCOME_COUPON, $result['outcome']);
    }

    /** 25 BD credit against a 40 BD trip leaves 15 to collect. */
    public function test_a_coupon_covers_part_of_a_bigger_trip(): void
    {
        $cancelled = $this->trip(25, '10 hours', paid: true);
        $coupon = app(TripCancellation::class)->cancel($cancelled)['coupon'];
        $this->assertNotNull($coupon);

        $next = $this->trip(40, '5 days', paid: false);
        $booking = LimoBooking::query()->findOrFail($next->legable_id);
        $booking->recalcTotal();
        $booking->save();

        $result = app(CouponRedeemer::class)->apply($coupon->code, $booking);

        $this->assertTrue($result['ok']);
        $this->assertSame(25.0, $result['applied']);
        $this->assertSame(15.0, $booking->fresh()?->balanceDue());
        $this->assertSame(0.0, $coupon->fresh()?->remaining());
    }

    /** 100 BD credit spent 15 at a time keeps its balance until it runs out. */
    public function test_a_coupon_is_spent_in_pieces_until_it_is_empty(): void
    {
        $cancelled = $this->trip(100, '10 hours', paid: true);
        $coupon = app(TripCancellation::class)->cancel($cancelled)['coupon'];
        $this->assertNotNull($coupon);

        $redeemer = app(CouponRedeemer::class);

        foreach ([15.0, 15.0, 15.0] as $fare) {
            $leg = $this->trip($fare, '5 days', paid: false);
            $booking = LimoBooking::query()->findOrFail($leg->legable_id);
            $booking->recalcTotal();
            $booking->save();

            $result = $redeemer->apply($coupon->code, $booking);

            $this->assertTrue($result['ok']);
            // Only what the trip needs is taken, never the whole coupon.
            $this->assertSame($fare, $result['applied']);
            // And that trip is now settled.
            $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->fresh()?->payment_status);
        }

        $this->assertSame(55.0, $coupon->fresh()?->remaining());
        $this->assertSame(3, $coupon->redemptions()->count());
    }

    public function test_an_expired_or_empty_coupon_is_refused(): void
    {
        $cancelled = $this->trip(25, '10 hours', paid: true);
        $coupon = app(TripCancellation::class)->cancel($cancelled)['coupon'];
        $this->assertNotNull($coupon);

        $coupon->forceFill(['expires_at' => now()->subDay()])->save();

        $next = $this->trip(40, '5 days', paid: false);
        $booking = LimoBooking::query()->findOrFail($next->legable_id);
        $booking->recalcTotal();
        $booking->save();

        $result = app(CouponRedeemer::class)->apply($coupon->code, $booking);

        $this->assertFalse($result['ok']);
        $this->assertSame(CouponRedeemer::ERROR_EXPIRED, $result['error']);
        // Nothing was taken from the customer's credit.
        $this->assertSame(25.0, $coupon->fresh()?->remaining());
    }

    /**
     * A finished trip is a record of what was driven, not a draft. The buttons
     * are hidden, but hiding a button is not a rule — the actions themselves
     * must refuse, or a crafted request could rewrite history.
     */
    public function test_a_completed_trip_cannot_be_edited_or_reassigned(): void
    {
        $leg = $this->trip(25, '-3 days', paid: true);
        $leg->forceFill(['status' => LimoLeg::STATUS_COMPLETED])->save();

        $component = \Livewire\Livewire::test(\Modules\Limousine\Livewire\Bookings::class);

        $component->call('openAssign', $leg->id)->assertSet('assigningId', null);
        $component->call('openEdit', $leg->legable_id, $leg->id)->assertSet('editingId', null);
    }

    public function test_a_cancelled_trip_is_closed_the_same_way(): void
    {
        $leg = $this->trip(25, '-3 days', paid: false);
        app(TripCancellation::class)->cancel($leg);

        \Livewire\Livewire::test(\Modules\Limousine\Livewire\Bookings::class)
            ->call('openAssign', $leg->id)
            ->assertSet('assigningId', null);
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $leg = $this->trip(40, '5 days', paid: false);
        $booking = LimoBooking::query()->findOrFail($leg->legable_id);

        $result = app(CouponRedeemer::class)->apply('CPN-9999', $booking);

        $this->assertFalse($result['ok']);
        $this->assertSame(CouponRedeemer::ERROR_NOT_FOUND, $result['error']);
    }

    public function test_with_the_coupon_rule_off_a_late_cancellation_keeps_the_payment(): void
    {
        app(TripCancellation::class)->setCouponRule(false);
        $leg = $this->trip(25, '10 hours', paid: true);

        $result = app(TripCancellation::class)->cancel($leg);

        $this->assertSame(TripCancellation::OUTCOME_FORFEITED, $result['outcome']);
        $this->assertNull($result['coupon']);
        $this->assertSame(0, LimoCoupon::query()->count());
        // The kept payment stays earned on the booking.
        $fresh = $leg->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->isBillable());
        $booking = $fresh->legable;
        $this->assertInstanceOf(LimoBooking::class, $booking);
        $this->assertSame(25.0, (float) $booking->fare);
    }

    public function test_with_the_coupon_rule_off_a_due_refund_is_always_money(): void
    {
        app(TripCancellation::class)->setCouponRule(false);
        $leg = $this->trip(25, '5 days', paid: true);

        $result = app(TripCancellation::class)->cancel($leg, null, refundAsCoupon: true);

        $this->assertSame(TripCancellation::OUTCOME_REFUNDED, $result['outcome']);
        $this->assertSame(0, LimoCoupon::query()->count());
    }

    public function test_only_the_owner_and_the_supervisor_accountant_can_switch_the_rule(): void
    {
        $this->grantEveryone('limousine.booking');
        $rule = app(TripCancellation::class);
        $this->assertTrue($rule->couponRuleOn());

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        \Livewire\Livewire::test(\Modules\Limousine\Livewire\LimoHome::class)
            ->assertDontSee(__('Coupon rule'))
            ->call('toggleCouponRule')
            ->assertForbidden();
        $this->assertTrue($rule->couponRuleOn());

        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        \Livewire\Livewire::test(\Modules\Limousine\Livewire\LimoHome::class)
            ->assertSee(__('Coupon rule'))
            ->call('toggleCouponRule');
        $this->assertFalse($rule->couponRuleOn());

        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        \Livewire\Livewire::test(\Modules\Limousine\Livewire\LimoHome::class)
            ->call('toggleCouponRule');
        $this->assertTrue($rule->couponRuleOn());
    }
}

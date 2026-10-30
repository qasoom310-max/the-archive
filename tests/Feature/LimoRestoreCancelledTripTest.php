<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\CouponRedeemer;
use Modules\Limousine\Services\TripCancellation;
use Tests\TestCase;

/**
 * A trip cancelled by mistake can be put back in the queue: from its row in
 * the bookings list, or for the whole booking from the booking page.
 */
final class LimoRestoreCancelledTripTest extends TestCase
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
        $customer = LimoCustomer::query()->create(['name' => 'Ministry of Defence']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/' . fake()->unique()->numberBetween(1000, 9999),
            'customer_id' => $customer->id,
            'pickup_at' => now()->add($startsIn),
            'status' => LimoBooking::STATUS_QUEUE,
            'fare' => $amount,
            'advance' => $paid ? $amount : 0,
            'payment_status' => $paid ? LimoBooking::PAYMENT_PAID : LimoBooking::PAYMENT_UNPAID,
        ]);

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'status' => LimoLeg::STATUS_QUEUE,
            'start_at' => now()->add($startsIn),
            'from_location' => 'Airport',
            'rate' => $amount, 'net_amount' => $amount,
        ]);

        return $leg->refresh();
    }

    public function test_a_cancelled_trip_goes_back_to_the_queue_and_back_on_the_bill(): void
    {
        $leg = $this->trip(30, '3 days', paid: false);
        app(TripCancellation::class)->cancel($leg, 'by mistake');
        $booking = LimoBooking::query()->findOrFail($leg->legable_id);
        $this->assertSame(LimoBooking::STATUS_CANCELLED, $booking->status);

        Livewire::test(Bookings::class)->call('restoreLeg', $leg->id);

        $leg = $leg->fresh();
        $this->assertSame(LimoLeg::STATUS_QUEUE, $leg?->status);
        $this->assertNull($leg?->cancelled_at);
        $this->assertNull($leg?->cancellation_reason);
        $this->assertNull($leg?->refund_outcome);
        $this->assertSame(LimoBooking::STATUS_QUEUE, $booking->fresh()?->status);
        $this->assertEqualsWithDelta(30.0, (float) $booking->fresh()?->fare, 0.001);
    }

    public function test_an_unused_coupon_is_withdrawn(): void
    {
        $leg = $this->trip(25, '10 hours', paid: true);
        app(TripCancellation::class)->cancel($leg);
        $this->assertSame(1, LimoCoupon::query()->count());

        $this->assertNull(app(TripCancellation::class)->restore($leg->fresh() ?? $leg));

        $this->assertSame(0, LimoCoupon::query()->count());
        $this->assertSame(LimoLeg::STATUS_QUEUE, $leg->fresh()?->status);
    }

    public function test_a_spent_coupon_blocks_the_restore(): void
    {
        $leg = $this->trip(25, '10 hours', paid: true);
        $coupon = app(TripCancellation::class)->cancel($leg)['coupon'];
        $this->assertNotNull($coupon);

        $next = $this->trip(40, '5 days', paid: false);
        $nextBooking = LimoBooking::query()->findOrFail($next->legable_id);
        $nextBooking->recalcTotal();
        $nextBooking->save();
        app(CouponRedeemer::class)->apply($coupon->code, $nextBooking);

        $refusal = app(TripCancellation::class)->restore($leg->fresh() ?? $leg);

        $this->assertNotNull($refusal);
        $this->assertSame(LimoLeg::STATUS_CANCELLED, $leg->fresh()?->status);
        $this->assertSame(1, LimoCoupon::query()->count());
    }

    public function test_the_booking_page_restores_a_cancelled_booking(): void
    {
        $leg = $this->trip(30, '3 days', paid: false);
        $booking = LimoBooking::query()->findOrFail($leg->legable_id);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('cancelBooking')
            ->assertSeeHtml('data-restore-booking')
            ->call('restoreBooking')
            ->assertHasNoErrors();

        $this->assertSame(LimoBooking::STATUS_QUEUE, $booking->fresh()?->status);
        $this->assertSame(LimoLeg::STATUS_QUEUE, $leg->fresh()?->status);
    }

    public function test_the_row_offers_restore_only_on_a_cancelled_trip(): void
    {
        $leg = $this->trip(30, '3 days', paid: false);

        Livewire::test(Bookings::class, ['tab' => 'all'])->assertDontSeeHtml('data-restore-leg="' . $leg->id . '"');

        app(TripCancellation::class)->cancel($leg);

        Livewire::test(Bookings::class)->set('tab', 'cancelled')
            ->assertSeeHtml('data-restore-leg="' . $leg->id . '"');
    }

    public function test_view_only_staff_cannot_restore(): void
    {
        $leg = $this->trip(30, '3 days', paid: false);
        app(TripCancellation::class)->cancel($leg);

        $staff = User::factory()->create(['is_admin' => false]);
        app(\App\Erp\Admin\UserProvisioner::class)->grantApps($staff, ['limousine'], \App\Erp\Admin\StaffRole::Staff);
        $this->actingAs($staff);

        Livewire::test(Bookings::class)->call('restoreLeg', $leg->id)->assertForbidden();
        $this->assertSame(LimoLeg::STATUS_CANCELLED, $leg->fresh()?->status);
    }
}

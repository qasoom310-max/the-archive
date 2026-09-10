<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * Cancelling through the queue's own dialog, and reading the Cancelled tab.
 *
 * The service behind it is covered elsewhere; this drives the screen, which is
 * where the office actually is when they press the button.
 */
final class LimoCancelUiTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private int $seq = 0;

    private function trip(bool $paid = false, string $startsIn = '+3 days'): LimoLeg
    {
        $this->seq++;
        $customer = LimoCustomer::query()->create(['name' => 'Amina Mohamed Mansoori']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/0000' . $this->seq,
            'customer_id' => $customer->id,
            'pickup_at' => now()->modify($startsIn),
            'status' => LimoBooking::STATUS_CONFIRMED,
            'fare' => 96,
            'advance' => $paid ? 96 : 50,
            'payment_status' => $paid ? LimoBooking::PAYMENT_PAID : LimoBooking::PAYMENT_UNPAID,
        ]);

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => (string) (10003 + $this->seq),
            'status' => LimoLeg::STATUS_CONFIRMED,
            'start_at' => now()->modify($startsIn),
            'from_location' => 'Hotel',
            'to_location' => 'Bahrain Airport',
            'rate' => 96, 'net_amount' => 96,
        ]);

        return $leg->refresh();
    }

    public function test_the_cancel_dialog_opens(): void
    {
        $leg = $this->trip();

        Livewire::test(Bookings::class)
            ->call('openCancel', $leg->id)
            ->assertSet('cancellingId', $leg->id)
            ->assertOk();
    }

    public function test_a_trip_can_be_cancelled_from_the_queue(): void
    {
        $leg = $this->trip();

        Livewire::test(Bookings::class)
            ->call('openCancel', $leg->id)
            ->set('cancelReason', 'customer changed plans')
            ->call('confirmCancel')
            ->assertOk();

        $this->assertSame(LimoLeg::STATUS_CANCELLED, $leg->fresh()?->status);
    }

    public function test_a_paid_trip_inside_the_window_cancels_to_a_coupon(): void
    {
        $leg = $this->trip(paid: true, startsIn: '+10 hours');

        Livewire::test(Bookings::class)
            ->call('openCancel', $leg->id)
            ->call('confirmCancel')
            ->assertOk();

        $this->assertSame(LimoLeg::STATUS_CANCELLED, $leg->fresh()?->status);
    }

    /** Reading the tab a cancelled trip lands on. */
    public function test_the_cancelled_tab_renders(): void
    {
        $leg = $this->trip();
        $leg->forceFill(['status' => LimoLeg::STATUS_CANCELLED])->save();

        Livewire::test(Bookings::class)
            ->set('tab', 'cancelled')
            ->assertOk()
            ->assertSee('10004');
    }

    /**
     * The office's actual booking: three legs — one cancelled, one completed,
     * one still confirmed — and only part of the money taken. Cancelling the
     * last live leg leaves the booking with nothing running.
     */
    public function test_cancelling_the_last_live_leg_of_a_part_paid_booking(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Amina Mohamed Mansoori']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00003',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDays(3),
            'status' => LimoBooking::STATUS_CONFIRMED,
            'advance' => 50,
            'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);

        $legs = [];
        foreach ([
            [LimoLeg::STATUS_CANCELLED, 96.0],
            [LimoLeg::STATUS_COMPLETED, 40.0],
            [LimoLeg::STATUS_CONFIRMED, 12.0],
        ] as $i => [$status, $amount]) {
            $legs[] = LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => $i,
                'reference' => (string) (20000 + $i),
                'status' => $status,
                'start_at' => now()->addDays(3),
                'from_location' => 'Hotel',
                'to_location' => 'Bahrain Airport',
                'rate' => $amount, 'net_amount' => $amount,
            ]);
        }

        $booking->recalcTotal();
        $booking->save();

        Livewire::test(Bookings::class)
            ->call('openCancel', $legs[2]->id)
            ->call('confirmCancel')
            ->assertOk();

        $this->assertSame(LimoLeg::STATUS_CANCELLED, $legs[2]->fresh()?->status);
        // Nothing left to run, and one leg was driven — so the job reads as done
        // rather than cancelled. Cancelled is only for a booking with no leg
        // that survived.
        $this->assertSame(LimoBooking::STATUS_COMPLETED, $booking->fresh()?->status);

        // And every tab still reads.
        foreach (['all', 'confirmed', 'completed', 'cancelled'] as $tab) {
            Livewire::test(Bookings::class)->set('tab', $tab)->assertOk();
        }
    }

    /**
     * The office's queue as it actually stands: ONE booking carrying six legs —
     * two cancelled, three driven, one still to run — part paid, with the
     * cancellations having left credit behind. Then the Cancelled tab, pressed
     * the way the tab bar presses it.
     */
    public function test_the_cancelled_tab_of_one_booking_with_many_legs(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Amina Mohamed Mansoori']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00003',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDays(3),
            'status' => LimoBooking::STATUS_CONFIRMED,
            'advance' => 50,
            'payment_status' => LimoBooking::PAYMENT_PAID,
        ]);

        $legs = [];
        foreach ([12.0, 40.0, 96.0, 25.0, 18.0, 33.0] as $i => $amount) {
            $legs[] = LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => $i,
                'reference' => (string) (30000 + $i),
                'status' => LimoLeg::STATUS_CONFIRMED,
                'start_at' => now()->addHours(10),
                'from_location' => 'Hotel',
                'to_location' => 'Bahrain Airport',
                'rate' => $amount, 'net_amount' => $amount,
            ]);
        }

        $booking->recalcTotal();
        $booking->save();

        // Two cancelled for real, so each leaves a coupon behind.
        foreach ([$legs[0], $legs[1]] as $off) {
            Livewire::test(Bookings::class)
                ->call('openCancel', $off->id)
                ->call('confirmCancel');
        }

        foreach ([$legs[2], $legs[3], $legs[4]] as $done) {
            $done->forceFill(['status' => LimoLeg::STATUS_COMPLETED])->save();
        }

        // Now press the tab, the way the tab bar does.
        Livewire::test(Bookings::class)
            ->set('tab', 'cancelled')
            ->assertOk()
            ->assertSee('30000')
            ->assertSee('30001');
    }

    /**
     * The Refund coupons page, over the credit a cancellation actually leaves.
     *
     * It is where a cancelled paid trip sends the office next, and it had no
     * test at all — an empty page passes by looking the same as a broken one.
     */
    public function test_the_coupons_page_renders_the_credit_a_cancellation_left(): void
    {
        $paid = $this->trip(paid: true, startsIn: '+10 hours');

        Livewire::test(Bookings::class)
            ->call('openCancel', $paid->id)
            ->set('cancelReason', 'customer changed plans')
            ->call('confirmCancel');

        $coupon = \Modules\Limousine\Models\LimoCoupon::query()->firstOrFail();

        foreach (['active', 'used', 'expired', 'all'] as $tab) {
            Livewire::test(\Modules\Limousine\Livewire\Coupons::class)
                ->set('tab', $tab)
                ->assertOk();
        }

        Livewire::test(\Modules\Limousine\Livewire\Coupons::class)
            ->assertSee($coupon->code)
            ->assertSee('Amina Mohamed Mansoori');
    }

    /**
     * Every tab, sorted every way — the whole grid the office can reach, over a
     * queue holding each state a leg can be in, including one cancelled through
     * the real path so it carries a refund outcome and a coupon.
     */
    public function test_every_tab_renders_under_every_sort(): void
    {
        // Cancelled the way the office cancels: paid, inside the window, so the
        // leg ends up with refund_outcome = coupon and a coupon behind it.
        $paid = $this->trip(paid: true, startsIn: '+10 hours');
        Livewire::test(Bookings::class)
            ->call('openCancel', $paid->id)
            ->call('confirmCancel');

        // A refund-due cancellation, the other outcome a row can show.
        $refunded = $this->trip(paid: true, startsIn: '+5 days');
        Livewire::test(Bookings::class)
            ->call('openCancel', $refunded->id)
            ->call('confirmCancel');

        foreach ([LimoLeg::STATUS_QUEUE, LimoLeg::STATUS_ACTIVE, LimoLeg::STATUS_COMPLETED] as $status) {
            $this->trip()->forceFill(['status' => $status])->save();
        }

        $this->trip();

        foreach (['all', 'queue', 'confirmed', 'active', 'completed', 'cancelled'] as $tab) {
            foreach (\Modules\Limousine\Services\LimoQueueRows::SORTS as $sort) {
                Livewire::test(Bookings::class)
                    ->set('tab', $tab)
                    ->set('sort', $sort)
                    ->assertOk();
            }
        }
    }
}

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
use Modules\Limousine\Services\TripCancellation;
use Tests\TestCase;

/**
 * What a cancelled trip does to the bill.
 *
 * A customer is not charged for a car that never came, so a called-off trip
 * comes off the total. The one exception is a trip cancelled too late to be
 * refunded: that money was forfeited and handed back as credit, so it stays
 * earned on the booking and the coupon carries the customer's half. Dropping it
 * from the bill as well would give the same money away twice.
 */
final class LimoCancelledLegBillingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private int $seq = 0;

    /**
     * The office's booking: 12 + 40 + 96, 50 taken, the 96 leg to be called off.
     *
     * @return array{0: LimoBooking, 1: list<LimoLeg>}
     */
    private function bookingOfThree(float $advance = 50, string $startsIn = '+5 days'): array
    {
        $this->seq++;
        $customer = LimoCustomer::query()->create(['name' => 'Amina Mohamed Mansoori']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/0000' . $this->seq,
            'customer_id' => $customer->id,
            'pickup_at' => now()->modify($startsIn),
            'status' => LimoBooking::STATUS_CONFIRMED,
            'advance' => $advance,
            'payment_status' => $advance > 0 ? LimoBooking::PAYMENT_UNPAID : LimoBooking::PAYMENT_UNPAID,
        ]);

        $legs = [];
        foreach ([12.0, 40.0, 96.0] as $i => $amount) {
            $legs[] = LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => $i,
                'reference' => (string) (40000 + ($this->seq * 10) + $i),
                'status' => LimoLeg::STATUS_CONFIRMED,
                'start_at' => now()->modify($startsIn),
                'from_location' => 'Hotel',
                'to_location' => 'Bahrain Airport',
                'rate' => $amount, 'net_amount' => $amount,
            ]);
        }

        $booking->recalcTotal();
        $booking->save();

        return [$booking, $legs];
    }

    /** The reported bug: 148 stayed 148 after a leg was called off. */
    public function test_cancelling_an_unpaid_trip_takes_it_off_the_bill(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        $this->assertSame(148.0, $booking->fresh()?->fare);

        app(TripCancellation::class)->cancel($legs[2], 'customer changed plans');

        $fresh = $booking->fresh();
        // 12 + 40. The 96 that never ran is not owed.
        $this->assertSame(52.0, $fresh?->fare);
        // 50 taken against 52 — 2 left, not the 98 it used to claim.
        $this->assertSame(2.0, $fresh?->balanceDue());
    }

    public function test_the_same_thing_through_the_queue_dialog(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        Livewire::test(Bookings::class)
            ->call('openCancel', $legs[2]->id)
            ->call('confirmCancel')
            ->assertOk();

        $this->assertSame(52.0, $booking->fresh()?->fare);
    }

    /**
     * Cancelled too late for a refund: the money was forfeited and returned as
     * credit, so it stays on the bill it was earned against.
     */
    public function test_a_forfeited_trip_stays_on_the_bill(): void
    {
        // Paid in full, and starting within the 48-hour window.
        [$booking, $legs] = $this->bookingOfThree(advance: 148, startsIn: '+10 hours');
        $booking->forceFill(['payment_status' => LimoBooking::PAYMENT_PAID])->save();

        $result = app(TripCancellation::class)->cancel($legs[2]);

        $this->assertSame(TripCancellation::OUTCOME_COUPON, $result['outcome']);
        $this->assertSame(96.0, $result['coupon']?->amount);

        // The bill is unchanged: that 96 was kept, and the coupon owes it back
        // in future service rather than in money off this booking.
        $this->assertSame(148.0, $booking->fresh()?->fare);
        $this->assertSame(0.0, $booking->fresh()?->balanceDue());
    }

    /** A refunded trip comes off the bill; the money goes back separately. */
    public function test_a_refunded_trip_comes_off_the_bill(): void
    {
        [$booking, $legs] = $this->bookingOfThree(advance: 148, startsIn: '+5 days');
        $booking->forceFill(['payment_status' => LimoBooking::PAYMENT_PAID])->save();

        $result = app(TripCancellation::class)->cancel($legs[2]);

        $this->assertSame(TripCancellation::OUTCOME_REFUNDED, $result['outcome']);
        $this->assertSame(52.0, $booking->fresh()?->fare);
    }

    /**
     * Losing the leg that was still owed for can settle a booking outright, so
     * the payment flag has to follow the new total.
     */
    public function test_a_booking_can_become_settled_by_losing_a_leg(): void
    {
        [$booking, $legs] = $this->bookingOfThree(advance: 52);

        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $booking->fresh()?->payment_status);

        app(TripCancellation::class)->cancel($legs[2]);

        $fresh = $booking->fresh();
        $this->assertSame(52.0, $fresh?->fare);
        $this->assertSame(0.0, $fresh?->balanceDue());
        $this->assertSame(LimoBooking::PAYMENT_PAID, $fresh?->payment_status);
    }

    /** Editing another leg later must not quietly put the cancelled one back. */
    public function test_re_pricing_a_surviving_leg_keeps_the_cancelled_one_off(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        app(TripCancellation::class)->cancel($legs[2]);

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $legs[0]->id)
            ->set('editLeg.rate', '20')
            ->call('saveEdit')
            ->assertHasNoErrors();

        // 20 + 40, still without the 96.
        $this->assertSame(60.0, $booking->fresh()?->fare);
    }

    public function test_a_leg_knows_whether_it_is_billable(): void
    {
        [, $legs] = $this->bookingOfThree();

        $this->assertTrue($legs[0]->isBillable());

        app(TripCancellation::class)->cancel($legs[2]);
        $this->assertFalse($legs[2]->fresh()?->isBillable());
    }

    /**
     * The bookings already in the system carry the old, inflated totals — they
     * were priced before a cancelled trip came off the bill and nothing would
     * re-price them until somebody happened to edit one. The migration walks
     * them, and it must move the payment flag too, since a booking can turn out
     * to have been settled all along.
     */
    public function test_the_backfill_repairs_bookings_already_priced_the_old_way(): void
    {
        [$booking, $legs] = $this->bookingOfThree(advance: 52);

        // Cancel the way the old code did: leg off, bill untouched.
        $legs[2]->forceFill([
            'status' => LimoLeg::STATUS_CANCELLED,
            'refund_outcome' => LimoLeg::REFUND_NONE,
        ])->save();
        $booking->forceFill([
            'fare' => 148, 'amount' => 148,
            'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ])->save();

        $this->assertSame(148.0, $booking->fresh()?->fare);

        $migration = require base_path(
            'Modules/Limousine/database/migrations/2026_08_31_950021_retotal_bookings_without_cancelled_legs.php'
        );
        $migration->up();

        $fresh = $booking->fresh();
        $this->assertSame(52.0, $fresh?->fare);
        $this->assertSame(52.0, $fresh?->amount);
        $this->assertSame(0.0, $fresh?->balanceDue());
        // 52 taken against 52 owed — settled, and it says so now.
        $this->assertSame(LimoBooking::PAYMENT_PAID, $fresh?->payment_status);
    }

    /** A booking whose total was already right is left exactly as it was. */
    public function test_the_backfill_leaves_a_correct_booking_alone(): void
    {
        [$booking] = $this->bookingOfThree();

        $migration = require base_path(
            'Modules/Limousine/database/migrations/2026_08_31_950021_retotal_bookings_without_cancelled_legs.php'
        );
        $migration->up();

        $fresh = $booking->fresh();
        $this->assertSame(148.0, $fresh?->fare);
        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $fresh?->payment_status);
    }

    /** The payment dialog shows what is not billed rather than a total that fails to add up. */
    public function test_the_payment_dialog_marks_a_cancelled_trip_as_not_billed(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        app(TripCancellation::class)->cancel($legs[2]);

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            ->assertOk()
            ->assertSee('Cancelled — not billed')
            // Balance offered is 2, not 98.
            ->assertSet('collectAmount', '2');
    }
}

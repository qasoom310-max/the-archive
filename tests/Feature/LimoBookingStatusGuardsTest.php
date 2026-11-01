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
use Modules\Limousine\Services\TripCancellation;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * A trip's status only moves forward, a trip that ran is never rewritten, and
 * a trip is cancelled once — with its money settled — whichever button does it:
 * the booking page's Confirm / Start / Complete / Cancel, or a list row whose
 * screen has gone stale.
 */
final class LimoBookingStatusGuardsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /**
     * @param  list<array{string, bool}>  $legs  [status, has a car] per trip
     */
    private function booking(array $legs, float $each = 30.0, bool $paid = false, string $startsIn = '3 days'): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => 'Gulf Air Crew Desk']);
        $car = new Vehicle();
        $car->forceFill(['name' => 'Lexus ES', 'plate_no' => '11111', 'status' => 'available', 'active' => true])->save();

        $total = $each * count($legs);
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/' . fake()->unique()->numberBetween(1000, 9999),
            'customer_id' => $customer->id,
            'pickup_at' => now()->add($startsIn),
            'status' => LimoBooking::STATUS_QUEUE,
            'fare' => $total,
            'advance' => $paid ? $total : 0,
            'payment_status' => $paid ? LimoBooking::PAYMENT_PAID : LimoBooking::PAYMENT_UNPAID,
        ]);

        foreach ($legs as $i => [$status, $hasCar]) {
            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => $i,
                'reference' => (string) (20000 + $i),
                'status' => $status,
                'start_at' => now()->add($startsIn),
                'from_location' => 'Airport',
                'to_location' => 'Seef',
                'car_id' => $hasCar ? $car->id : null,
                'rate' => $each, 'net_amount' => $each,
            ]);
        }
        $booking->syncStatusFromLegs();

        return $booking->refresh();
    }

    /** @return list<string> */
    private function statuses(LimoBooking $booking): array
    {
        return $booking->legs()->orderBy('sequence')->pluck('status')->all();
    }

    public function test_the_booking_pages_buttons_never_rewrite_a_trip_that_ran(): void
    {
        $booking = $this->booking([[LimoLeg::STATUS_COMPLETED, true], [LimoLeg::STATUS_CONFIRMED, true]]);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('start')
            ->assertHasNoErrors();
        $this->assertSame([LimoLeg::STATUS_COMPLETED, LimoLeg::STATUS_ACTIVE], $this->statuses($booking));
        $this->assertSame(LimoBooking::STATUS_ACTIVE, $booking->refresh()->status);

        Livewire::test(BookingForm::class, ['id' => $booking->id])->call('complete');
        $this->assertSame([LimoLeg::STATUS_COMPLETED, LimoLeg::STATUS_COMPLETED], $this->statuses($booking));
    }

    public function test_a_cancelled_trip_without_a_car_does_not_hold_up_start(): void
    {
        $booking = $this->booking([[LimoLeg::STATUS_CANCELLED, false], [LimoLeg::STATUS_QUEUE, true]]);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('start')
            ->assertHasNoErrors();

        $this->assertSame([LimoLeg::STATUS_CANCELLED, LimoLeg::STATUS_ACTIVE], $this->statuses($booking));
    }

    public function test_start_says_which_trip_has_no_car(): void
    {
        $booking = $this->booking([[LimoLeg::STATUS_QUEUE, false]]);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('start')
            ->assertHasErrors(['start', 'legs.0.car_id'])
            ->assertSee('20000');

        $this->assertSame([LimoLeg::STATUS_QUEUE], $this->statuses($booking));
    }

    public function test_cancelling_from_the_booking_page_settles_each_trip_like_the_list(): void
    {
        // Paid, three days out, coupons off: a full refund is due on the trip
        // still to run; the one already driven stays as it was.
        $booking = $this->booking([[LimoLeg::STATUS_COMPLETED, true], [LimoLeg::STATUS_CONFIRMED, true]], paid: true);
        $invoice = $booking->createInvoice();

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('cancelBooking')
            ->assertRedirect(url('/app/limousine/booking/' . $booking->id));

        $this->assertSame([LimoLeg::STATUS_COMPLETED, LimoLeg::STATUS_CANCELLED], $this->statuses($booking));
        $cancelled = $booking->legs()->where('status', LimoLeg::STATUS_CANCELLED)->sole();
        $this->assertSame(TripCancellation::OUTCOME_REFUNDED, $cancelled->refund_outcome);

        // Off the bill, the refund off the money held, the invoice following.
        $booking->refresh();
        $this->assertSame(30.0, (float) $booking->fare);
        $this->assertSame(30.0, (float) $booking->advance);
        $this->assertSame(30.0, (float) $invoice->refresh()->total);
        $this->assertSame(LimoBooking::STATUS_COMPLETED, $booking->status);
    }

    public function test_a_trip_is_cancelled_once_only(): void
    {
        $cancellation = app(TripCancellation::class);
        $cancellation->setCouponRule(true);
        // Paid and inside the 48 hours: cancelling hands out a coupon.
        $booking = $this->booking([[LimoLeg::STATUS_CONFIRMED, true]], paid: true, startsIn: '10 hours');
        $leg = $booking->legs()->sole();

        $first = $cancellation->cancel($leg);
        $second = $cancellation->cancel($leg);

        $this->assertSame(TripCancellation::OUTCOME_COUPON, $first['outcome']);
        $this->assertSame(TripCancellation::OUTCOME_NONE, $second['outcome']);
        $this->assertSame(1, LimoCoupon::query()->count());
    }

    public function test_a_stale_list_row_cannot_move_a_trip_back_or_revive_a_closed_one(): void
    {
        $booking = $this->booking([[LimoLeg::STATUS_ACTIVE, true], [LimoLeg::STATUS_COMPLETED, true], [LimoLeg::STATUS_QUEUE, true]]);
        [$active, $completed, $queued] = $booking->legs()->orderBy('sequence')->get()->all();

        Livewire::test(Bookings::class)
            // Started from the staff app; the row still offered Confirm.
            ->call('advanceLeg', $active->id, LimoLeg::STATUS_CONFIRMED)
            ->call('advanceLeg', $completed->id, LimoLeg::STATUS_ACTIVE)
            // Cancelling is the cancel dialog's job, with the money rules.
            ->call('advanceLeg', $queued->id, LimoLeg::STATUS_CANCELLED);

        $this->assertSame([LimoLeg::STATUS_ACTIVE, LimoLeg::STATUS_COMPLETED, LimoLeg::STATUS_QUEUE], $this->statuses($booking));
    }
}

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
 * Taking the rest of the money on a booking of several trips.
 *
 * The money question the office asked: three trips on one booking, 50 BD taken
 * up front — is that one bill or three? One. The customer settles the JOB, so
 * `received` and `balance` belong to the booking and are printed on each of its
 * rows, not divided between them. These tests hold that line: paying once pays
 * the booking, and it can never be paid twice over.
 */
final class LimoCollectPaymentTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /**
     * The office's own booking: 12 + 40 + 96 = 148, with 50 taken at the start.
     *
     * @return array{0: LimoBooking, 1: list<LimoLeg>}
     */
    private function bookingOfThree(): array
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

        // All three still on the bill. What a CANCELLED leg does to the total is
        // its own question, answered in LimoCancelledLegBillingTest — mixing one
        // in here would only make these figures argue about two things at once.
        $legs = [];
        foreach ([
            [LimoLeg::STATUS_CONFIRMED, 12.0],
            [LimoLeg::STATUS_COMPLETED, 40.0],
            [LimoLeg::STATUS_COMPLETED, 96.0],
        ] as $i => [$status, $amount]) {
            $legs[] = LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => $i,
                'reference' => (string) (10003 + $i),
                'status' => $status,
                'start_at' => now()->addDays(3),
                'from_location' => 'Hotel',
                'to_location' => 'Bahrain Airport',
                'rate' => $amount,
                'net_amount' => $amount,
            ]);
        }

        $booking->recalcTotal();
        $booking->save();

        return [$booking, $legs];
    }

    /** The 50 is held once for the booking, not once per trip. */
    public function test_the_money_is_one_account_for_the_whole_booking(): void
    {
        [$booking] = $this->bookingOfThree();

        $fresh = $booking->fresh();
        $this->assertSame(148.0, $fresh?->fare);
        $this->assertSame(50.0, $fresh?->advance);
        $this->assertSame(98.0, $fresh?->balanceDue());
    }

    public function test_the_dialog_opens_on_the_booking_from_any_of_its_trips(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        foreach ($legs as $leg) {
            Livewire::test(Bookings::class)
                ->call('openCollect', $leg->id)
                ->assertSet('collectingId', $booking->id)
                // Offered as the whole remaining balance, whichever row was clicked.
                ->assertSet('collectAmount', '98');
        }
    }

    /**
     * BK/15624: a converted fare left 159.998 owed, shown as "160.00", and
     * typing 160 was refused as more than the 160.00 still owed.
     */
    public function test_the_balance_as_shown_on_screen_settles_the_booking(): void
    {
        [$booking, $legs] = $this->bookingOfThree();
        $booking->forceFill(['fare' => 159.998, 'amount' => 159.998, 'advance' => 0])->saveQuietly();

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            ->set('collectAmount', '160')
            ->call('saveCollect')
            ->assertHasNoErrors();

        $fresh = $booking->fresh();
        $this->assertSame(0.0, $fresh?->balanceDue());
        $this->assertEqualsWithDelta(159.998, (float) $fresh?->advance, 0.0001);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $fresh?->payment_status);
    }

    public function test_a_real_overpayment_is_still_refused(): void
    {
        [$booking, $legs] = $this->bookingOfThree();
        $booking->forceFill(['fare' => 159.998, 'amount' => 159.998, 'advance' => 0])->saveQuietly();

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            ->set('collectAmount', '160.01')
            ->call('saveCollect')
            ->assertHasErrors(['collectAmount' => 'max']);
    }

    public function test_taking_the_rest_settles_the_booking(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            ->call('saveCollect')
            ->assertHasNoErrors();

        $fresh = $booking->fresh();
        // 50 already taken plus the 98 owed — added to, not replaced.
        $this->assertSame(148.0, $fresh?->advance);
        $this->assertSame(0.0, $fresh?->balanceDue());
        $this->assertSame(LimoBooking::PAYMENT_PAID, $fresh?->payment_status);
    }

    public function test_a_part_payment_leaves_the_rest_owing(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            ->set('collectAmount', '48')
            ->call('saveCollect')
            ->assertHasNoErrors();

        $fresh = $booking->fresh();
        $this->assertSame(98.0, $fresh?->advance);
        $this->assertSame(50.0, $fresh?->balanceDue());
        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $fresh?->payment_status);
    }

    /** Two payments in a row add up rather than the second replacing the first. */
    public function test_payments_accumulate(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        foreach ([40, 58] as $paid) {
            Livewire::test(Bookings::class)
                ->call('openCollect', $legs[0]->id)
                ->set('collectAmount', (string) $paid)
                ->call('saveCollect')
                ->assertHasNoErrors();
        }

        $fresh = $booking->fresh();
        $this->assertSame(148.0, $fresh?->advance);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $fresh?->payment_status);
    }

    /**
     * The double-charge guard: paying from a second trip's row must not take
     * the balance again, because there is only one balance.
     */
    public function test_a_booking_cannot_be_paid_twice_over(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            ->call('saveCollect')
            ->assertHasNoErrors();

        // Settled. Now try again from a different trip of the same booking.
        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[1]->id)
            ->set('collectAmount', '98')
            ->call('saveCollect')
            ->assertHasErrors('collectAmount');

        $this->assertSame(148.0, $booking->fresh()?->advance);
    }

    public function test_more_than_the_balance_is_refused(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            ->set('collectAmount', '500')
            ->call('saveCollect')
            ->assertHasErrors('collectAmount');

        $this->assertSame(50.0, $booking->fresh()?->advance);
    }

    /**
     * The note is kept on the RECEIPT, which is where a note about a payment
     * belongs — it explains that money, not the booking. It used to be stamped
     * into the booking's comments because there was nowhere else for it to go;
     * now every payment has its own piece of paper.
     */
    public function test_a_note_is_kept_with_the_payment(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            ->set('collectAmount', '10')
            ->set('collectMethod', 'cheque')
            ->set('collectNote', 'cheque 4471')
            ->call('saveCollect')
            ->assertHasNoErrors();

        $receipt = \Modules\Limousine\Models\LimoReceipt::query()->sole();
        $this->assertStringContainsString('cheque 4471', (string) $receipt->notes);
        $this->assertSame('cheque', $receipt->method);

        // And how the money came in is remembered on the booking too.
        $this->assertSame('cheque', $booking->fresh()?->payment_method);
    }

    /**
     * The receipt is dated the day the money arrived, not the day it was typed.
     *
     * An office writing up Saturday's cash on Monday would otherwise date every
     * receipt Monday, and the customer's own record would disagree with ours.
     */
    public function test_a_payment_can_be_dated_the_day_it_was_received(): void
    {
        [, $legs] = $this->bookingOfThree();
        $saturday = now()->subDays(2)->toDateString();

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            // It opens on today, which is the usual answer.
            ->assertSet('collectDate', now()->toDateString())
            ->set('collectAmount', '10')
            ->set('collectDate', $saturday)
            ->call('saveCollect')
            ->assertHasNoErrors();

        $receipt = \Modules\Limousine\Models\LimoReceipt::query()->sole();
        $this->assertSame($saturday, $receipt->date?->toDateString());
    }

    /** Left alone, it is today — the same receipt as before the field existed. */
    public function test_a_payment_typed_today_is_dated_today(): void
    {
        [, $legs] = $this->bookingOfThree();

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            ->set('collectAmount', '10')
            ->call('saveCollect')
            ->assertHasNoErrors();

        $this->assertSame(
            now()->toDateString(),
            \Modules\Limousine\Models\LimoReceipt::query()->sole()->date?->toDateString(),
        );
    }

    /** Money cannot have arrived tomorrow, and nothing is recorded if it is claimed. */
    public function test_a_payment_cannot_be_dated_in_the_future(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        Livewire::test(Bookings::class)
            ->call('openCollect', $legs[0]->id)
            ->set('collectAmount', '10')
            ->set('collectDate', now()->addDay()->toDateString())
            ->call('saveCollect')
            ->assertHasErrors('collectDate');

        $this->assertSame(0, \Modules\Limousine\Models\LimoReceipt::query()->count());
        $this->assertSame(50.0, round((float) $booking->fresh()?->advance, 3));
    }

    /** The button is only offered while there is something left to take. */
    public function test_a_settled_booking_offers_no_payment_button(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        // These legs are confirmed/completed, so the tab is named: the subject
        // here is the payment button on the row, not which tab a trip sits in.
        $queue = fn () => Livewire::withQueryParams(['tab' => 'all'])->test(Bookings::class);

        $queue()->assertSee('Receive payment for this booking');

        $booking->forceFill(['advance' => 148, 'payment_status' => LimoBooking::PAYMENT_PAID])->save();

        $queue()->assertDontSee('Receive payment for this booking');
    }
}

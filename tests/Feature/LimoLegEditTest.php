<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * Editing ONE trip of a booking that holds several.
 *
 * A booking can be three legs — one cancelled, one completed, one still to run.
 * Clicking edit on the row for the third has to open that trip, not all three:
 * the full booking form is a long way to change one pick-up, and it puts the
 * other two under the same cursor.
 */
final class LimoLegEditTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /**
     * One booking, three legs: cancelled, completed, and one still confirmed —
     * the shape the office actually reported this against.
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
        ]);

        $legs = [];
        foreach ([
            [LimoLeg::STATUS_CANCELLED, 'Home', 96.0],
            [LimoLeg::STATUS_COMPLETED, 'Bahrain Airport', 40.0],
            [LimoLeg::STATUS_CONFIRMED, 'Hotel', 12.0],
        ] as $i => [$status, $from, $amount]) {
            $legs[] = LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => $i,
                'reference' => (string) (10003 + $i),
                'status' => $status,
                'start_at' => now()->addDays(3),
                'from_location' => $from,
                'to_location' => 'Bahrain Airport',
                'rate' => $amount,
                'net_amount' => $amount,
            ]);
        }

        $booking->recalcTotal();
        $booking->save();

        return [$booking, $legs];
    }

    public function test_edit_opens_the_clicked_trip_not_the_whole_booking(): void
    {
        [$booking, $legs] = $this->bookingOfThree();
        $live = $legs[2];

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $live->id)
            ->assertSet('editingLegId', $live->id)
            // Loaded with THAT leg's details, not the first leg's and not a blank.
            ->assertSet('editLeg.from_location', 'Hotel')
            ->assertSet('editLeg.rate', '12');
    }

    public function test_saving_changes_only_the_clicked_trip(): void
    {
        [$booking, $legs] = $this->bookingOfThree();
        [$cancelled, $completed, $live] = $legs;

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $live->id)
            ->set('editLeg.from_location', 'Seef Mall')
            ->set('editLeg.to_location', 'Bahrain Airport')
            ->set('editLeg.rate', '30')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame('Seef Mall', $live->fresh()?->from_location);
        $this->assertSame(30.0, $live->fresh()?->net_amount);

        // The other two legs are untouched — that is the whole point.
        $this->assertSame('Home', $cancelled->fresh()?->from_location);
        $this->assertSame(96.0, $cancelled->fresh()?->net_amount);
        $this->assertSame('Bahrain Airport', $completed->fresh()?->from_location);
        $this->assertSame(40.0, $completed->fresh()?->net_amount);
    }

    /** Re-pricing one leg re-prices the job, since the fare is their sum. */
    public function test_the_booking_total_follows_the_edited_trip(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        $this->assertSame(148.0, $booking->fresh()?->fare);

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $legs[2]->id)
            ->set('editLeg.rate', '20')
            ->call('saveEdit')
            ->assertHasNoErrors();

        // 96 + 40 + 20.
        $this->assertSame(156.0, $booking->fresh()?->fare);
    }

    /** A trip switched to chauffeur is a car at disposal — it has no drop-off. */
    public function test_a_trip_can_be_switched_to_chauffeur_from_the_dialog(): void
    {
        [$booking, $legs] = $this->bookingOfThree();
        $live = $legs[2];

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $live->id)
            ->set('editLeg.service_type', LimoLeg::TYPE_CHAUFFEUR)
            ->set('editLeg.hours', '8')
            ->set('editLeg.days', '3')
            ->set('editLeg.rate', '20')
            ->set('editLeg.rate_basis', LimoLeg::BASIS_HOUR)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $fresh = $live->fresh();
        $this->assertSame(LimoLeg::TYPE_CHAUFFEUR, $fresh?->service_type);
        $this->assertSame(8.0, $fresh?->hours);
        $this->assertSame(3, $fresh?->days);
        $this->assertSame(480.0, $fresh?->net_amount);
        $this->assertNull($fresh?->to_location);
    }

    public function test_a_trip_still_needs_a_pickup_and_a_drop_off(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $legs[2]->id)
            ->set('editLeg.from_location', '')
            ->set('editLeg.to_location', '')
            ->call('saveEdit')
            ->assertHasErrors(['editLeg.from_location', 'editLeg.to_location']);

        // Nothing was written on a failed save.
        $this->assertSame('Hotel', $legs[2]->fresh()?->from_location);
    }

    /** The completed and cancelled legs of the same booking stay closed. */
    public function test_a_finished_trip_still_refuses_to_open(): void
    {
        [$booking, $legs] = $this->bookingOfThree();

        foreach ([$legs[0], $legs[1]] as $closed) {
            Livewire::test(Bookings::class)
                ->call('openEdit', $booking->id, $closed->id)
                ->assertSet('editingId', null)
                ->assertSet('editLeg', []);
        }
    }

    /**
     * A trip finished by someone else while the dialog sat open must not be
     * written by the save that follows.
     */
    public function test_a_trip_completed_while_the_dialog_was_open_is_not_written(): void
    {
        [$booking, $legs] = $this->bookingOfThree();
        $live = $legs[2];

        $component = Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $live->id)
            ->set('editLeg.from_location', 'Seef Mall');

        $live->forceFill(['status' => LimoLeg::STATUS_COMPLETED])->save();

        $component->call('saveEdit');

        $this->assertSame('Hotel', $live->fresh()?->from_location);
    }

    /** Raising a leg past what was taken means the booking owes money again. */
    public function test_a_dearer_trip_makes_a_paid_booking_unpaid(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00009',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_CONFIRMED,
            'advance' => 25,
            'payment_status' => LimoBooking::PAYMENT_PAID,
        ]);

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '10099',
            'status' => LimoLeg::STATUS_CONFIRMED,
            'start_at' => now()->addDay(),
            'from_location' => 'Home',
            'to_location' => 'Airport',
            'rate' => 25, 'net_amount' => 25,
        ]);

        $booking->recalcTotal();
        $booking->save();

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id, $leg->id)
            ->set('editLeg.rate', '60')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $fresh = $booking->fresh();
        $this->assertSame(60.0, $fresh?->fare);
        $this->assertSame(35.0, $fresh?->balanceDue());
        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $fresh?->payment_status);
    }

    /**
     * A confirmed trip belonged to no dashboard card: not queued, not active,
     * not completed — agreed with the customer and counted nowhere.
     */
    public function test_the_dashboard_counts_confirmed_trips(): void
    {
        $this->bookingOfThree();

        Livewire::test(LimoHome::class)
            ->assertOk()
            ->assertViewHas('confirmed', 1)
            ->assertSee('Confirmed Trips');
    }
}

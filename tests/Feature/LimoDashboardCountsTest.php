<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * The dashboard cards must agree with the queue they link to.
 *
 * A leg is the trip — it is what the queue lists and what its tabs count — but
 * the cards counted BOOKINGS. A booking takes the status of its least
 * progressed live leg, so a job with one leg queued and another already running
 * reads "queue": the card showed 0 Active while the Active tab it links to
 * listed a running trip.
 */
final class LimoDashboardCountsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function bookingWithLegs(string ...$legStatuses): LimoBooking
    {
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/' . fake()->unique()->numberBetween(1000, 9999),
            'pickup_at' => now(),
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        foreach ($legStatuses as $i => $status) {
            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => $i + 1,
                'status' => $status,
                'start_at' => now(),
                'rate' => 10,
                'net_amount' => 10,
            ]);
        }

        $booking->syncStatusFromLegs();

        return $booking->refresh();
    }

    /** The exact shape that read 0 Active on the dashboard while a trip ran. */
    public function test_a_running_trip_counts_even_when_a_sibling_leg_is_still_queued(): void
    {
        $booking = $this->bookingWithLegs(LimoLeg::STATUS_QUEUE, LimoLeg::STATUS_ACTIVE);

        // The booking itself reads "queue" — the least progressed live leg wins.
        $this->assertSame(LimoBooking::STATUS_QUEUE, $booking->status);

        Livewire::test(LimoHome::class)
            ->assertViewHas('active', 1)     // the running leg is counted
            ->assertViewHas('queue', 1);     // and so is the waiting one
    }

    public function test_counts_follow_the_legs_not_the_booking(): void
    {
        $this->bookingWithLegs(LimoLeg::STATUS_ACTIVE, LimoLeg::STATUS_ACTIVE);
        $this->bookingWithLegs(LimoLeg::STATUS_COMPLETED);

        Livewire::test(LimoHome::class)
            ->assertViewHas('active', 2)
            ->assertViewHas('completed', 1)
            ->assertViewHas('queue', 0);
    }

    /**
     * The day cards count what their own page SHOWS, cancelled trips included.
     *
     * They used to leave a cancelled trip out, on the grounds that it is not
     * running — true, but they are links now, and a card whose number disagrees
     * with the list it opens is the exact complaint the KPI cards above were
     * fixed for. The label says Bookings, and a called-off booking is still one
     * that was made for that day.
     */
    public function test_the_day_counts_match_the_day_they_open(): void
    {
        $this->bookingWithLegs(LimoLeg::STATUS_ACTIVE, LimoLeg::STATUS_CANCELLED);

        Livewire::test(LimoHome::class)->assertViewHas('todayCount', 2);
    }
}

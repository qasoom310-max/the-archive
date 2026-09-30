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
     * The day cards count what their own page SHOWS, and neither counts a
     * cancelled trip.
     *
     * The rule that holds is the card agreeing with the list it opens; which
     * side of the argument they agree ON has moved twice. The cards once left
     * cancelled trips out, then counted them so the number matched the page —
     * and the office then said the page itself was wrong: a trip called off is
     * not tomorrow's work, whatever the label says. So both drop it, and the
     * Cancelled tab is where a called-off trip is looked up.
     */
    public function test_the_day_counts_match_the_day_they_open(): void
    {
        $this->bookingWithLegs(LimoLeg::STATUS_ACTIVE, LimoLeg::STATUS_CANCELLED);

        Livewire::test(LimoHome::class)->assertViewHas('todayCount', 1);
    }

    /** The In queue / Active / Today boxes at the top open the list they count. */
    public function test_the_glance_boxes_link_to_their_lists(): void
    {
        $today = now()->toDateString();

        Livewire::test(LimoHome::class)
            ->assertSeeHtml('href="' . url('/app/limousine/booking') . '?tab=queue" wire:navigate data-glance-link')
            ->assertSeeHtml('href="' . url('/app/limousine/booking') . '?tab=active" wire:navigate data-glance-link')
            ->assertSeeHtml('href="' . url('/app/limousine/booking') . '?tab=all&amp;from=' . $today . '&amp;to=' . $today . '" wire:navigate data-glance-link');
    }
}

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
 * Yesterday / Today / Tomorrow open the day they count.
 *
 * A number on a dashboard that cannot be clicked is a fact you then have to go
 * and look up by hand — filtering the queue to the same date the card already
 * knows. Each card is now the way in.
 *
 * And the number has to agree with the page it opens, which is the same rule
 * the booking KPIs above it were already fixed for once.
 */
final class LimoScheduleCardsTest extends TestCase
{
    use DatabaseMigrations;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function tripOn(string $when, string $status = LimoLeg::STATUS_CONFIRMED): LimoLeg
    {
        $this->seq++;
        $customer = LimoCustomer::query()->create(['name' => 'Helen']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/0000' . $this->seq,
            'customer_id' => $customer->id,
            'pickup_at' => now()->modify($when),
            'status' => LimoBooking::STATUS_CONFIRMED,
        ]);

        return LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => (string) (60000 + $this->seq),
            'status' => $status,
            'start_at' => now()->modify($when),
            'from_location' => 'Hotel',
            'to_location' => 'Airport',
            'rate' => 45, 'net_amount' => 45,
        ]);
    }

    public function test_each_card_links_to_its_own_day(): void
    {
        $this->tripOn('today');

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        Livewire::test(LimoHome::class)
            ->assertViewHas('todayDate', $today)
            ->assertViewHas('yesterdayDate', $yesterday)
            ->assertViewHas('tomorrowDate', $tomorrow)
            // The link the card carries: the queue, filtered to that one day,
            // across every status — the count spans them, so the page must too.
            ->assertSee('/app/limousine/booking?tab=all&from=' . $today . '&to=' . $today, false)
            ->assertSee('/app/limousine/booking?tab=all&from=' . $yesterday . '&to=' . $yesterday, false)
            ->assertSee('/app/limousine/booking?tab=all&from=' . $tomorrow . '&to=' . $tomorrow, false);
    }

    public function test_the_counts_are_per_day(): void
    {
        $this->tripOn('yesterday');
        $this->tripOn('yesterday');
        $this->tripOn('today');

        Livewire::test(LimoHome::class)
            ->assertViewHas('yesterdayCount', 2)
            ->assertViewHas('todayCount', 1)
            ->assertViewHas('tomorrowCount', 0);
    }

    /**
     * The rule that matters: press the card and the list has exactly that many
     * rows. A cancelled trip still shows on the day it was booked for, so it is
     * counted — the card and the page must not disagree.
     */
    public function test_the_number_matches_the_page_it_opens(): void
    {
        $this->tripOn('today');
        $this->tripOn('today', LimoLeg::STATUS_CANCELLED);

        $today = now()->toDateString();

        Livewire::test(LimoHome::class)->assertViewHas('todayCount', 2);

        // Pressing the card: it links with tab=all, because the count spans
        // every status while the queue itself now opens on Queue.
        $queue = Livewire::withQueryParams(['tab' => 'all', 'from' => $today, 'to' => $today])
            ->test(Bookings::class);

        $this->assertCount(2, $queue->viewData('legs')->items());
    }

    /**
     * The card opens the QUEUE, not a cut-down view of it.
     *
     * Landing there with the date pre-filled means everything the queue can do
     * is still on the page — the tabs, the search, the sortable columns, the
     * exports, the page size and every row action — because it IS the queue,
     * with one filter already set.
     */
    public function test_the_day_opens_the_full_queue_with_the_date_filled_in(): void
    {
        $this->tripOn('today');
        $today = now()->toDateString();

        $queue = Livewire::withQueryParams(['tab' => 'all', 'from' => $today, 'to' => $today])
            ->test(Bookings::class)
            // The filter arrived from the card's link.
            ->assertSet('from', $today)
            ->assertSet('to', $today)
            ->assertOk();

        // …and the rest of the queue came with it.
        $queue->assertSee('Unpaid')            // every tab
            ->assertSee('Cancelled')
            ->assertSee('Reference, customer, passenger, route…')  // search
            ->assertSee('CSV')                  // exports
            ->assertSee('Print')
            ->assertSee('Sort by Amount')     // sortable column headers
            ->assertSee('Show');                // page size

        // Sorting still works from there.
        $queue->call('sortBy', 'amount')->assertSet('sort', 'amount')->assertOk();

        // And the date filter is still holding after it.
        $queue->assertSet('from', $today);
        $this->assertCount(1, $queue->viewData('legs')->items());
    }

    /** A trip on another day is not on this one. */
    public function test_other_days_are_left_out(): void
    {
        $this->tripOn('today');
        $this->tripOn('+3 days');

        $today = now()->toDateString();

        Livewire::test(LimoHome::class)->assertViewHas('todayCount', 1);

        $queue = Livewire::withQueryParams(['tab' => 'all', 'from' => $today, 'to' => $today])
            ->test(Bookings::class);
        $this->assertCount(1, $queue->viewData('legs')->items());
    }
}

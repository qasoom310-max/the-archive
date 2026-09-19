<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\LimoQueueRows;
use Tests\TestCase;

/**
 * A cancelled trip is not part of a day's work.
 *
 * The schedule cards on the limousine home open the queue on `tab=all` for
 * one date — "Tomorrow's Bookings". That view showed every status, so a trip
 * the office had called off kept turning up in tomorrow's list as though it
 * were still going to run.
 */
final class LimoCancelledDayViewTest extends TestCase
{
    use DatabaseMigrations;

    private const DAY = '2026-09-20';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function leg(string $status, string $customer = 'Amal Al Nasser'): LimoLeg
    {
        $party = LimoCustomer::query()->create(['name' => $customer]);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/' . mt_rand(10000, 99999),
            'customer_id' => $party->id,
            'pickup_at' => self::DAY . ' 07:30:00',
            'status' => $status === LimoLeg::STATUS_CANCELLED
                ? LimoBooking::STATUS_CANCELLED
                : LimoBooking::STATUS_QUEUE,
        ]);

        return LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'reference' => (string) mt_rand(20000, 29999),
            'status' => $status,
            'start_at' => self::DAY . ' 07:30:00',
            'from_location' => 'Dumistan Villa151',
            'to_location' => 'Dharan',
            'rate' => 35, 'net_amount' => 35,
        ]);
    }

    /** @return list<int> */
    private function dayLegIds(string $search = ''): array
    {
        return app(LimoQueueRows::class)
            ->query('all', self::DAY, self::DAY, $search)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function test_a_cancelled_trip_is_not_in_that_days_bookings(): void
    {
        $live = $this->leg(LimoLeg::STATUS_QUEUE, 'Welcome Pickups');
        $cancelled = $this->leg(LimoLeg::STATUS_CANCELLED);

        $ids = $this->dayLegIds();

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    /**
     * The card's number has to equal the rows its own link opens — a card
     * saying 4 that opens a list of 3 is its own bug.
     */
    public function test_the_home_card_counts_what_its_link_opens(): void
    {
        Carbon::setTestNow(Carbon::parse(self::DAY)->subDay());

        $this->leg(LimoLeg::STATUS_QUEUE, 'Welcome Pickups');
        $this->leg(LimoLeg::STATUS_QUEUE, 'Braxtone Plus W.L.L');
        $this->leg(LimoLeg::STATUS_CANCELLED);

        $card = Livewire::test(LimoHome::class)->viewData('tomorrowCount');

        $this->assertSame(2, $card);
        $this->assertCount($card, $this->dayLegIds());

        Carbon::setTestNow();
    }

    /**
     * Looking a customer up is not planning a day — the customer page links
     * here for their whole history, so a search still finds a cancelled trip.
     */
    public function test_a_search_still_finds_a_cancelled_trip(): void
    {
        $cancelled = $this->leg(LimoLeg::STATUS_CANCELLED);

        $this->assertContains($cancelled->id, $this->dayLegIds('Amal Al Nasser'));
    }

    /**
     * `limo_legs.status` is nullable, and SQL compares nothing to NULL
     * successfully — so "not cancelled" written as a bare `!=` throws away
     * every leg that has no status yet, which is a far bigger hole than the
     * one being plugged.
     */
    public function test_a_leg_with_no_status_at_all_is_still_shown(): void
    {
        $leg = $this->leg(LimoLeg::STATUS_QUEUE, 'Welcome Pickups');
        $leg->status = null;
        $leg->save();

        $this->assertContains($leg->id, $this->dayLegIds());
    }

    public function test_the_cancelled_tab_still_lists_them(): void
    {
        $cancelled = $this->leg(LimoLeg::STATUS_CANCELLED);

        $ids = app(LimoQueueRows::class)->query('cancelled')->pluck('id')->all();

        $this->assertContains($cancelled->id, $ids);
    }

    /** And the day's own list on screen agrees with the query behind it. */
    public function test_the_screen_leaves_the_cancelled_trip_out(): void
    {
        $this->leg(LimoLeg::STATUS_QUEUE, 'Welcome Pickups');
        $this->leg(LimoLeg::STATUS_CANCELLED);

        Livewire::test(Bookings::class)
            ->set('tab', 'all')
            ->set('from', self::DAY)
            ->set('to', self::DAY)
            ->assertSee('Welcome Pickups')
            ->assertDontSee('Amal Al Nasser');
    }
}

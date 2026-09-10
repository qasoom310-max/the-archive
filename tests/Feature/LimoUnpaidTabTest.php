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
use Modules\Limousine\Services\LimoQueueRows;
use Tests\TestCase;

/**
 * The Unpaid tab: money still to collect.
 *
 * Not a stage a trip is at — a job can be queued, driven or finished and still
 * unpaid — so it filters on the BOOKING's balance rather than the leg's status.
 * Payment belongs to the booking because the customer settles the whole job.
 */
final class LimoUnpaidTabTest extends TestCase
{
    use DatabaseMigrations;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /** One booking, one leg, priced and part-paid however the test needs. */
    private function trip(string $legStatus, float $fare, float $advance): LimoLeg
    {
        $this->seq++;
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/0000' . $this->seq,
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_QUEUE,
            'advance' => $advance,
        ]);

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => (string) (50000 + $this->seq),
            'status' => $legStatus,
            'start_at' => now()->addDay(),
            'from_location' => 'Hotel',
            'to_location' => 'Airport',
            'rate' => $fare, 'net_amount' => $fare,
        ]);

        $booking->recalcTotal();
        $booking->save();
        $booking->syncPaymentFromAdvance();

        return $leg->refresh();
    }

    /** @return list<string> */
    private function unpaidRows(): array
    {
        $rows = app(LimoQueueRows::class)->all('unpaid', '', '', '');

        return array_map(fn (array $r): string => (string) $r['reference'], $rows);
    }

    public function test_it_lists_trips_whose_booking_still_owes(): void
    {
        $owing = $this->trip(LimoLeg::STATUS_QUEUE, 100, 40);

        $this->assertSame([$owing->reference], $this->unpaidRows());
    }

    public function test_a_settled_booking_is_not_in_it(): void
    {
        $this->trip(LimoLeg::STATUS_QUEUE, 100, 100);

        $this->assertSame([], $this->unpaidRows());
    }

    /**
     * Being owed for a trip is not a stage — a finished job that was never paid
     * for is exactly what the office is chasing.
     */
    public function test_it_spans_every_stage_a_trip_can_be_at(): void
    {
        $queued = $this->trip(LimoLeg::STATUS_QUEUE, 100, 0);
        $active = $this->trip(LimoLeg::STATUS_ACTIVE, 100, 0);
        $done = $this->trip(LimoLeg::STATUS_COMPLETED, 100, 0);

        $rows = $this->unpaidRows();

        foreach ([$queued, $active, $done] as $leg) {
            $this->assertContains($leg->reference, $rows);
        }
    }

    /** A called-off trip is not work waiting to be paid for. */
    public function test_cancelled_trips_are_left_out(): void
    {
        $cancelled = $this->trip(LimoLeg::STATUS_CANCELLED, 100, 0);

        $this->assertNotContains($cancelled->reference, $this->unpaidRows());
    }

    /** A booking priced at nothing owes nothing, whatever its flag says. */
    public function test_a_booking_with_no_fare_is_not_money_to_chase(): void
    {
        $free = $this->trip(LimoLeg::STATUS_QUEUE, 0, 0);

        $this->assertNotContains($free->reference, $this->unpaidRows());
    }

    public function test_the_tab_and_its_count_show_on_the_queue(): void
    {
        $owing = $this->trip(LimoLeg::STATUS_COMPLETED, 100, 0);
        $this->trip(LimoLeg::STATUS_QUEUE, 100, 100);

        Livewire::test(Bookings::class)
            ->assertSee('Unpaid')
            ->assertViewHas('unpaidCount', 1)
            ->set('tab', 'unpaid')
            ->assertOk()
            ->assertSee($owing->reference);
    }

    /** The tab is a filter like any other: sorting and search still apply. */
    public function test_it_still_sorts_and_searches(): void
    {
        $this->trip(LimoLeg::STATUS_QUEUE, 100, 0);
        $this->trip(LimoLeg::STATUS_QUEUE, 30, 0);

        $rows = app(LimoQueueRows::class)->all('unpaid', '', '', '', 'amount', 'desc');
        $this->assertSame([100.0, 30.0], array_map(fn (array $r): float => $r['amount'], $rows));

        $this->assertCount(2, app(LimoQueueRows::class)->all('unpaid', '', '', 'Helen'));
        $this->assertCount(0, app(LimoQueueRows::class)->all('unpaid', '', '', 'Nobody'));
    }
}

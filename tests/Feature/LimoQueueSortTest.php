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
 * Ordering the trip queue by any column it prints.
 *
 * The ordering is done in SQL rather than over the fetched page — sorting the
 * twenty rows already on screen by amount would put the biggest of THAT page on
 * top and present it as the biggest there is.
 */
final class LimoQueueSortTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /**
     * Three trips that disagree on every column, so any sort has a distinct
     * right answer: cheapest is newest, dearest is oldest.
     */
    private function seedThree(): void
    {
        $trips = [
            ['ref' => '10001', 'customer' => 'Charlie', 'days' => '-1 day', 'amount' => 96.0, 'advance' => 0.0, 'from' => 'Home'],
            ['ref' => '10002', 'customer' => 'Amina', 'days' => '+2 days', 'amount' => 40.0, 'advance' => 40.0, 'from' => 'Bahrain Airport'],
            ['ref' => '10003', 'customer' => 'Bilal', 'days' => '+5 days', 'amount' => 12.0, 'advance' => 5.0, 'from' => 'Hotel'],
        ];

        foreach ($trips as $t) {
            $customer = LimoCustomer::query()->create(['name' => $t['customer']]);

            $booking = LimoBooking::query()->create([
                'reference' => 'BK/' . $t['ref'],
                'customer_id' => $customer->id,
                'pickup_at' => now()->modify($t['days']),
                'status' => LimoBooking::STATUS_QUEUE,
                'fare' => $t['amount'],
                'advance' => $t['advance'],
                'prepared_by' => $t['customer'],
            ]);

            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => 0,
                'reference' => $t['ref'],
                'status' => LimoLeg::STATUS_QUEUE,
                'start_at' => now()->modify($t['days']),
                'from_location' => $t['from'],
                'rate' => $t['amount'],
                'net_amount' => $t['amount'],
            ]);
        }
    }

    /** @return list<string> */
    private function order(string $sort, string $dir, string $column = 'reference'): array
    {
        $rows = app(LimoQueueRows::class)->all('all', '', '', '', $sort, $dir);

        return array_map(fn (array $r): string => (string) $r[$column], $rows);
    }

    public function test_amount_sorts_both_ways(): void
    {
        $this->seedThree();

        $this->assertSame(['10001', '10002', '10003'], $this->order('amount', 'desc'));
        $this->assertSame(['10003', '10002', '10001'], $this->order('amount', 'asc'));
    }

    public function test_date_sorts_both_ways(): void
    {
        $this->seedThree();

        $this->assertSame(['10003', '10002', '10001'], $this->order('from_date', 'desc'));
        $this->assertSame(['10001', '10002', '10003'], $this->order('from_date', 'asc'));
    }

    public function test_the_running_number_sorts_both_ways(): void
    {
        $this->seedThree();

        $this->assertSame(['10003', '10002', '10001'], $this->order('reference', 'desc'));
        $this->assertSame(['10001', '10002', '10003'], $this->order('reference', 'asc'));
    }

    /** Customer lives on the parent booking, so this one sorts through a join. */
    public function test_customer_sorts_alphabetically(): void
    {
        $this->seedThree();

        $this->assertSame(['Amina', 'Bilal', 'Charlie'], $this->order('customer', 'asc', 'customer'));
        $this->assertSame(['Charlie', 'Bilal', 'Amina'], $this->order('customer', 'desc', 'customer'));
    }

    /** Balance is fare less advance, computed in SQL to match what is printed. */
    public function test_balance_sorts_by_what_is_still_owed(): void
    {
        $this->seedThree();

        // 96 owed, 7 owed, 0 owed.
        $this->assertSame(['10001', '10003', '10002'], $this->order('balance', 'desc'));
        $this->assertSame(['10002', '10003', '10001'], $this->order('balance', 'asc'));
    }

    public function test_received_sorts_through_the_booking(): void
    {
        $this->seedThree();

        $this->assertSame(['10002', '10003', '10001'], $this->order('received', 'desc'));
    }

    public function test_pickup_sorts_alphabetically(): void
    {
        $this->seedThree();

        $this->assertSame(['Bahrain Airport', 'Home', 'Hotel'], $this->order('pickup', 'asc', 'pickup'));
    }

    /**
     * A chauffeur job ends days after it starts, and the To date column says so
     * — so ordering by that column has to use the end, not the start.
     */
    public function test_to_date_sorts_by_when_the_trip_actually_ends(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen']);

        // A one-day transfer tomorrow, and a five-day chauffeur job starting
        // today: the chauffeur job starts FIRST but ends LAST.
        foreach ([['short', '+1 day', 1], ['long', '+0 day', 5]] as [$ref, $when, $days]) {
            $booking = LimoBooking::query()->create([
                'reference' => 'BK/' . $ref,
                'customer_id' => $customer->id,
                'pickup_at' => now()->modify($when),
                'status' => LimoBooking::STATUS_QUEUE,
            ]);

            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => 0,
                'reference' => $ref,
                'status' => LimoLeg::STATUS_QUEUE,
                'service_type' => $days > 1 ? LimoLeg::TYPE_CHAUFFEUR : LimoLeg::TYPE_TRANSFER,
                'start_at' => now()->modify($when),
                'from_location' => 'Manama',
                'days' => $days,
            ]);
        }

        // By start, the chauffeur job is first; by end, it is last.
        $this->assertSame(['long', 'short'], $this->order('from_date', 'asc'));
        $this->assertSame(['short', 'long'], $this->order('to_date', 'asc'));
    }

    /** Sorting must reach the whole list, not reorder the page on screen. */
    public function test_sorting_orders_every_page_not_just_the_visible_one(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen']);

        // 25 trips — more than one page of 20. The dearest is created last, so
        // it lands on page 2 in the default order.
        for ($i = 1; $i <= 25; $i++) {
            $booking = LimoBooking::query()->create([
                'reference' => 'BK/' . $i,
                'customer_id' => $customer->id,
                'pickup_at' => now()->subDays($i),
                'status' => LimoBooking::STATUS_QUEUE,
                'fare' => $i * 10,
            ]);

            LimoLeg::query()->create([
                'legable_type' => LimoBooking::class,
                'legable_id' => $booking->id,
                'sequence' => 0,
                'reference' => (string) (10000 + $i),
                'status' => LimoLeg::STATUS_QUEUE,
                'start_at' => now()->subDays($i),
                'from_location' => 'Manama',
                'net_amount' => $i * 10,
            ]);
        }

        // 250 BD is the biggest of all 25, and it is not on the first page by
        // date — sorting by amount must still bring it to the top.
        Livewire::test(Bookings::class)
            ->call('sortBy', 'amount')
            ->assertSet('sort', 'amount')
            ->assertSet('dir', 'desc')
            ->assertSeeInOrder(['10025', '10024', '10023']);
    }

    public function test_clicking_the_same_column_turns_it_around(): void
    {
        $this->seedThree();

        Livewire::test(Bookings::class)
            ->call('sortBy', 'amount')->assertSet('dir', 'desc')
            ->call('sortBy', 'amount')->assertSet('dir', 'asc')
            ->call('sortBy', 'amount')->assertSet('dir', 'desc');
    }

    /**
     * Dates and money open on the end people mean; names open A→Z. One click,
     * not two.
     */
    public function test_a_column_opens_on_its_useful_end(): void
    {
        // The queue already opens on from_date desc, so each of these is a
        // switch TO a column rather than a flip of the one already sorting.
        Livewire::test(Bookings::class)
            ->call('sortBy', 'customer')->assertSet('dir', 'asc')
            ->call('sortBy', 'balance')->assertSet('dir', 'desc')
            ->call('sortBy', 'pickup')->assertSet('dir', 'asc')
            ->call('sortBy', 'booked_time')->assertSet('dir', 'desc')
            ->call('sortBy', 'from_date')->assertSet('dir', 'desc');
    }

    /** A column name is user input; it may never reach SQL unchecked. */
    public function test_an_unknown_column_is_refused(): void
    {
        Livewire::test(Bookings::class)
            ->call('sortBy', 'net_amount; drop table limo_legs')
            ->assertSet('sort', 'from_date')
            ->assertOk();

        $this->assertSame(0, LimoLeg::query()->count());
        // The table is still there to be counted.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('limo_legs'));
    }

    /** Every column the table prints offers a sort, so none looks broken. */
    public function test_every_printed_column_can_be_sorted(): void
    {
        $this->seedThree();

        $columns = array_keys(app(LimoQueueRows::class)->headings());

        foreach ($columns as $column) {
            $this->assertContains($column, LimoQueueRows::SORTS, "Column {$column} has no sort.");

            // And each one actually runs rather than producing bad SQL.
            $this->assertCount(3, app(LimoQueueRows::class)->all('all', '', '', '', $column, 'asc'));
            $this->assertCount(3, app(LimoQueueRows::class)->all('all', '', '', '', $column, 'desc'));
        }
    }

    /** Sorting narrows nothing: the filters still hold. */
    public function test_sorting_keeps_the_tab_and_search_filters(): void
    {
        $this->seedThree();

        $rows = app(LimoQueueRows::class)->all('all', '', '', 'Amina', 'amount', 'desc');

        $this->assertCount(1, $rows);
        $this->assertSame('10002', $rows[0]['reference']);
    }
}

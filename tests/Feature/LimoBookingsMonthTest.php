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
 * On the Yesterday / Today / Tomorrow pages the tabs are the MONTH's: their
 * counts cover the month of the day shown, and opening one lists that tab for
 * the whole month, not every trip since the first booking.
 */
final class LimoBookingsMonthTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');

        $this->trip('2026-09-30 10:00', LimoLeg::STATUS_COMPLETED);
        $this->trip('2026-10-02 10:00', LimoLeg::STATUS_COMPLETED);
        $this->trip('2026-10-18 06:00', LimoLeg::STATUS_QUEUE);
        $this->trip('2026-10-25 09:00', LimoLeg::STATUS_QUEUE, 0);
        $this->trip('2026-11-03 09:00', LimoLeg::STATUS_QUEUE);
    }

    private function trip(string $at, string $status, float $advance = 10): void
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'C ' . $at])->id,
            'status' => LimoBooking::STATUS_QUEUE,
            'advance' => $advance,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 1,
            'status' => $status, 'start_at' => $at,
            'from_location' => 'A', 'to_location' => 'B', 'rate' => 10, 'net_amount' => 10,
        ]);
        LimoBooking::query()->whereKey($booking->id)->update(['fare' => 10]);
    }

    public function test_a_day_page_counts_the_month_of_that_day(): void
    {
        $page = Livewire::withQueryParams(['tab' => 'all', 'from' => '2026-10-18', 'to' => '2026-10-18'])
            ->test(Bookings::class);

        $counts = $page->viewData('counts');
        $this->assertSame(1, (int) $counts->get(LimoLeg::STATUS_COMPLETED), 'only October\'s completed trip');
        $this->assertSame(2, (int) $counts->get(LimoLeg::STATUS_QUEUE), 'not November\'s');
        $this->assertSame(1, $page->viewData('unpaidCount'), 'the unpaid October trip');
        $page->assertSee('Counts for October 2026');

        // The list itself is still the day.
        $this->assertCount(1, $page->viewData('legs')->items());
    }

    public function test_opening_a_tab_from_a_day_page_shows_that_month(): void
    {
        $page = Livewire::withQueryParams(['tab' => 'all', 'from' => '2026-10-18', 'to' => '2026-10-18'])
            ->test(Bookings::class)
            ->call('openTab', 'all')
            ->assertSet('from', '2026-10-01')
            ->assertSet('to', '2026-10-31')
            ->assertSet('tab', 'all')
            ->assertSee('Bookings for October 2026');

        $this->assertCount(3, $page->viewData('legs')->items(), 'October\'s three trips, not September\'s or November\'s');

        $page->call('openTab', 'queue');
        $this->assertCount(2, $page->viewData('legs')->items());
    }

    public function test_a_month_page_steps_a_month_at_a_time(): void
    {
        Livewire::withQueryParams(['tab' => 'all', 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->test(Bookings::class)
            ->call('shiftMonth', 1)
            ->assertSet('from', '2026-11-01')
            ->assertSet('to', '2026-11-30')
            ->assertSee('Bookings for November 2026')
            ->call('shiftMonth', -1)
            ->call('shiftMonth', -1)
            ->assertSet('from', '2026-09-01')
            ->assertSet('to', '2026-09-30');
    }

    public function test_without_a_day_the_counts_and_tabs_are_unchanged(): void
    {
        $page = Livewire::test(Bookings::class)->call('openTab', 'completed')
            ->assertSet('from', '')
            ->assertSet('to', '')
            ->assertDontSee('Counts for');

        $this->assertSame(2, (int) $page->viewData('counts')->get(LimoLeg::STATUS_COMPLETED));
    }

    public function test_an_unknown_tab_is_ignored(): void
    {
        Livewire::withQueryParams(['tab' => 'all', 'from' => '2026-10-18', 'to' => '2026-10-18'])
            ->test(Bookings::class)
            ->call('openTab', 'everything')
            ->assertSet('tab', 'all')
            ->assertSet('from', '2026-10-18');
    }
}

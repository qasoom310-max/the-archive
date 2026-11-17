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
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\LimoQueueRows;
use Tests\TestCase;

/**
 * A day page (Yesterday / Today / Tomorrow) has driver tabs: one per driver
 * with trips that day, plus "No driver" for the trips nobody has yet. On a
 * phone they sit in a notch pulled down from the top of the list.
 */
final class LimoBookingDriverTabsTest extends TestCase
{
    use DatabaseMigrations;

    private LimoDriver $sohail;

    private LimoDriver $habib;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');

        $this->sohail = LimoDriver::query()->create(['name' => 'Sohail']);
        $this->habib = LimoDriver::query()->create(['name' => 'Habib']);

        $this->trip('2026-10-18 06:00', $this->sohail);
        $this->trip('2026-10-18 09:00', $this->sohail);
        $this->trip('2026-10-18 11:00', $this->habib);
        $this->trip('2026-10-18 13:00', null);
        $this->trip('2026-10-18 15:00', null, 'kown');
        // Another day: never counted on the 18th.
        $this->trip('2026-10-19 09:00', $this->habib);
    }

    private function trip(string $at, ?LimoDriver $driver, ?string $typed = null): void
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'C ' . $at])->id,
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 1,
            'status' => LimoLeg::STATUS_QUEUE, 'start_at' => $at,
            'from_location' => 'A', 'to_location' => 'B', 'rate' => 10, 'net_amount' => 10,
            'driver_id' => $driver?->id, 'driver' => $driver?->displayName() ?? $typed,
        ]);
    }

    /** @param  array<string, string>  $extra */
    private function day(array $extra = []): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::withQueryParams(['tab' => 'all', 'from' => '2026-10-18', 'to' => '2026-10-18'] + $extra)
            ->test(Bookings::class);
    }

    public function test_a_day_page_lists_its_drivers_with_no_driver_first(): void
    {
        $tabs = $this->day()->viewData('dayDrivers');

        $this->assertSame(
            [
                ['key' => LimoQueueRows::DRIVER_NONE, 'label' => 'No driver', 'count' => 1],
                ['key' => 'd' . $this->sohail->id, 'label' => 'Sohail', 'count' => 2],
                ['key' => 'd' . $this->habib->id, 'label' => 'Habib', 'count' => 1],
                ['key' => 'n:kown', 'label' => 'kown', 'count' => 1],
            ],
            $tabs,
        );
    }

    public function test_picking_a_driver_shows_only_their_trips_that_day(): void
    {
        $page = $this->day()->call('setDriver', 'd' . $this->sohail->id);
        $this->assertCount(2, $page->viewData('legs')->items());
        $this->assertStringContainsString('driver=d' . $this->sohail->id, $page->viewData('exportQuery'));
        // The tabs still list every driver of the day, not just the picked one.
        $this->assertCount(4, $page->viewData('dayDrivers'));

        $page->call('setDriver', LimoQueueRows::DRIVER_NONE);
        $this->assertCount(1, $page->viewData('legs')->items(), 'only the trip with no driver at all');

        $page->call('setDriver', 'n:kown');
        $this->assertCount(1, $page->viewData('legs')->items());

        $page->call('setDriver', '');
        $this->assertCount(5, $page->viewData('legs')->items());
    }

    public function test_an_unknown_key_shows_nothing_rather_than_everything(): void
    {
        $this->assertCount(0, $this->day(['driver' => 'bogus'])->viewData('legs')->items());
    }

    public function test_the_notch_is_for_phones_and_only_on_a_day_page(): void
    {
        $html = $this->day()->html();
        $this->assertStringContainsString('data-driver-notch', $html);
        $this->assertSame(1, preg_match('/class="([^"]*)"\s+x-data="\{ open: false, y: null \}"/', $html, $m));
        $this->assertStringContainsString('sm:hidden', $m[1], 'the notch is for phones only');

        // A range has no driver tabs, and a driver left in the URL is ignored.
        $range = Livewire::withQueryParams(['tab' => 'all', 'from' => '2026-10-01', 'to' => '2026-10-31', 'driver' => 'd' . $this->habib->id])
            ->test(Bookings::class)
            ->assertDontSeeHtml('data-driver-notch');
        $this->assertCount(6, $range->viewData('legs')->items());
    }

    public function test_a_laptop_sees_which_driver_is_picked_and_can_clear_it(): void
    {
        $this->day(['driver' => 'd' . $this->habib->id])
            ->assertSeeHtml('data-driver-active')
            ->assertSee('Show all drivers')
            ->call('setDriver', '')
            ->assertDontSeeHtml('data-driver-active');
    }

    public function test_opening_a_tab_drops_the_driver(): void
    {
        $this->day(['driver' => 'd' . $this->habib->id])
            ->call('openTab', 'queue')
            ->assertSet('driver', '');
    }

    public function test_the_export_prints_the_picked_drivers_trips(): void
    {
        $rows = app(LimoQueueRows::class)->all('all', '2026-10-18', '2026-10-18', '', '', 'asc', false, 'd' . $this->sohail->id);
        $this->assertCount(2, $rows);
    }
}

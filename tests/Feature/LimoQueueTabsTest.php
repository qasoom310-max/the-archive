<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Tests\TestCase;

/**
 * The queue is a place of work, not an archive.
 *
 * Every tab is something someone has to do, so there is no "All" — a catch-all
 * mixing cancelled and completed trips into the live ones was only ever a
 * longer list to scroll past. Search and the date range still reach anything.
 */
final class LimoQueueTabsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    public function test_the_screen_opens_on_the_queue_and_offers_no_all_tab(): void
    {
        Livewire::test(Bookings::class)
            ->assertSet('tab', 'queue')
            // The other stages are still there — only the catch-all is gone.
            ->assertSee(__('Queue'))
            ->assertSee(__('Completed'))
            ->assertSee(__('Cancelled'))
            ->assertDontSee('>' . __('All') . '<', false);
    }

    public function test_all_is_still_reachable_by_link_though_it_is_not_a_tab(): void
    {
        // The schedule cards on the app home open a whole day across every
        // status, and their promise is that the number on the card equals the
        // rows on the page. So the VALUE survives even though the button is
        // gone — dropping both would have quietly broken that.
        Livewire::withQueryParams(['tab' => 'all'])
            ->test(Bookings::class)
            ->assertSet('tab', 'all');
    }

    public function test_a_nonsense_tab_falls_back_to_the_queue(): void
    {
        // Without this it would render with no tab lit up, which reads as a
        // broken page rather than a deliberate view.
        Livewire::withQueryParams(['tab' => 'nonsense'])
            ->test(Bookings::class)
            ->assertSet('tab', 'queue');
    }

    public function test_a_real_tab_in_the_url_is_still_honoured(): void
    {
        Livewire::withQueryParams(['tab' => 'cancelled'])
            ->test(Bookings::class)
            ->assertSet('tab', 'cancelled');
    }
}

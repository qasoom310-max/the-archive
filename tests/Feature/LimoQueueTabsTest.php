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
 * The bookings tabs. The screen still opens on the queue — the work to do —
 * and "All" lists every trip that is happening or happened. Cancelled trips
 * keep their own tab rather than padding All.
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

    private function trip(string $reference, string $status): void
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'C ' . $reference])->id,
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'reference' => $reference,
            'status' => $status,
            'start_at' => '2026-09-20 10:00:00',
            'from_location' => 'A', 'to_location' => 'B',
            'rate' => 10, 'net_amount' => 10,
        ]);
    }

    public function test_the_screen_opens_on_the_queue_and_offers_an_all_tab(): void
    {
        Livewire::test(Bookings::class)
            ->assertSet('tab', 'queue')
            ->assertSee(__('Queue'))
            ->assertSee(__('Completed'))
            ->assertSee(__('Cancelled'))
            ->assertSeeHtml("\$set('tab', 'all')");
    }

    public function test_all_lists_every_trip_but_the_cancelled_ones_and_counts_them(): void
    {
        $this->trip('77001', LimoLeg::STATUS_QUEUE);
        $this->trip('77002', LimoLeg::STATUS_ACTIVE);
        $this->trip('77003', LimoLeg::STATUS_COMPLETED);
        $this->trip('77004', LimoLeg::STATUS_CANCELLED);

        $page = Livewire::test(Bookings::class)->set('tab', 'all');

        $page->assertSee('77001')->assertSee('77002')->assertSee('77003')
            ->assertDontSee('77004');

        // The number on the tab matches the rows under it.
        $this->assertMatchesRegularExpression(
            "/\\\$set\('tab', 'all'\)\"[^>]*>\s*" . preg_quote(__('All'), '/') . "\s*<span[^>]*>3<\/span>/",
            $page->html(),
        );
    }

    public function test_all_is_reachable_by_link_for_the_schedule_cards(): void
    {
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

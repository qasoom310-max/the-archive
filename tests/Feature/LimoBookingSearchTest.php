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

final class LimoBookingSearchTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function trip(string $customer, string $phone, string $at, string $from = 'Riffa'): LimoLeg
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => $customer, 'phone' => $phone])->id,
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        return $booking->legs()->create([
            'sequence' => 0, 'service_type' => LimoLeg::TYPE_TRANSFER, 'status' => LimoLeg::STATUS_QUEUE,
            'from_location' => $from, 'to_location' => 'Airport', 'start_at' => $at,
            'rate' => 20, 'rate_basis' => 'trip', 'net_amount' => 20,
        ]);
    }

    public function test_a_search_finds_only_that_customers_trips(): void
    {
        $this->trip('Salem Al Hajri', '+966568840000', '2026-01-28 20:30');
        $this->trip('Sara', '+97333219121', '2026-05-27 12:30');
        $this->trip('Mohammed Alomair', '+966500000001', '2026-09-01 10:00');

        $html = Livewire::test(Bookings::class)
            ->call('openTab', 'all')
            ->set('dir', 'asc')
            ->set('search', 'Mohammed Alomair')
            ->html();

        $this->assertStringContainsString('Mohammed Alomair', $html);
        $this->assertStringNotContainsString('Salem Al Hajri', $html);
        $this->assertStringNotContainsString('Sara', $html);
    }

    /**
     * The ETA heading used to leave the loop with @continue inside its @if,
     * which skipped Livewire's closing marker for that @if. Leaving a tab with
     * ETA for one without then crashed the redraw in the browser (checked in
     * headless Chrome), and the list stopped following the search.
     */
    public function test_the_heading_loop_has_no_continue_inside_an_if(): void
    {
        $view = (string) file_get_contents(base_path('Modules/Limousine/resources/views/bookings.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/^s*@continues*$/m', $view);

        $queue = Livewire::test(Bookings::class)->call('openTab', 'queue')->html();
        $all = Livewire::test(Bookings::class)->call('openTab', 'all')->html();
        $this->assertSame(substr_count($queue, '<!--[if BLOCK]>'), substr_count($queue, '<!--[if ENDBLOCK]>'));
        $this->assertSame(substr_count($all, '<!--[if BLOCK]>'), substr_count($all, '<!--[if ENDBLOCK]>'));
    }

    public function test_an_empty_tab_says_the_search_matches_trips_on_other_tabs(): void
    {
        $this->trip('Mohammed Alomair', '+966500000001', '2026-09-01 10:00')->update(['status' => LimoLeg::STATUS_COMPLETED]);

        Livewire::test(Bookings::class)
            ->call('openTab', 'queue')
            ->set('search', 'Alomair')
            ->assertSee('No bookings found.')
            ->assertSee('1 matching trip is on another tab.')
            ->call('openTab', 'all')
            ->assertSee('Mohammed Alomair')
            ->assertDontSee('matching trip is on another tab');
    }
}

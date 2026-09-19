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
 * What the trip queue shows on a phone.
 *
 * A transfer starts and ends at the same moment, so From date and To date
 * printed the identical timestamp twice — half a narrow screen spent saying
 * one thing. The second date column now waits for a wider screen and the
 * price takes its place, beside the date rather than five columns along.
 */
final class LimoQueuePhoneColumnsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function trip(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '33112233']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/15478',
            'customer_id' => $customer->id,
            'pickup_at' => '2026-09-17 17:01:00',
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'reference' => '15478',
            'status' => LimoLeg::STATUS_QUEUE,
            'start_at' => '2026-09-17 17:01:00',
            'from_location' => 'Arad',
            'to_location' => 'Bahrain airport',
            'rate' => 14, 'net_amount' => 14,
        ]);
    }

    public function test_a_phone_shows_one_date_and_the_price_in_place_of_the_second(): void
    {
        $this->trip();

        $html = Livewire::test(Bookings::class)->html();

        // The price is on screen at every width; the second date waits for one.
        $this->assertStringContainsString(
            '<td class="px-2 py-2 text-end font-medium text-chrome-900"',
            $html,
        );
        $this->assertStringContainsString(
            '<td class="hidden px-2 py-2 text-chrome-900 sm:table-cell">',
            $html,
        );
    }

    /** With only one date on a phone, its heading is just "Date". */
    public function test_the_remaining_date_heading_reads_date_on_a_phone(): void
    {
        $this->trip();

        $html = Livewire::test(Bookings::class)->html();

        $this->assertStringContainsString('<span class="sm:hidden">Date</span>', $html);
        $this->assertStringContainsString('<span class="hidden sm:inline">From date</span>', $html);
    }

    public function test_the_price_column_comes_before_the_type_on_screen(): void
    {
        $this->trip();

        $html = Livewire::test(Bookings::class)->html();

        $this->assertLessThan(
            mb_strpos($html, 'Sort by Type'),
            mb_strpos($html, 'Sort by Amount'),
            'The price belongs with the dates, not past the customer.',
        );
        $this->assertLessThan(
            mb_strpos($html, 'Transfer</td>'),
            mb_strpos($html, 'Price of this trip'),
        );
    }

    /**
     * Grey-on-white at this size is hard to read on a phone held at arm's
     * length in a car park, so every FACT in the row is near-black. What is
     * not a fact — the dash standing in for an empty cell, the sort arrows,
     * the menu's icons — stays light, or the table loses its shape.
     */
    public function test_the_rows_facts_read_near_black_and_only_markers_stay_light(): void
    {
        $this->trip();

        $html = Livewire::test(Bookings::class)->html();

        foreach (['17-Sep-26', 'Arad', 'Bahrain airport', 'Helen Friberg'] as $fact) {
            $this->assertStringContainsString($fact, $html);
        }

        // The cells carrying those facts.
        $this->assertStringContainsString('<td class="px-2 py-2 text-chrome-900">', $html);
        $this->assertStringNotContainsString('<td class="px-2 py-2 text-chrome-600">', $html);

        // The sort arrows and the menu's own icons are markers, not facts.
        $this->assertStringContainsString('text-chrome-300', $html);
        $this->assertStringContainsString('size-4 shrink-0 text-chrome-400', $html);
    }

    /**
     * The sort arrows are desktop-only. On a phone they cost more room than
     * they earn beside a heading that already has to wrap, and tapping the
     * heading sorts whether an arrow is drawn on it or not.
     */
    public function test_the_sort_arrows_are_hidden_on_a_phone(): void
    {
        $this->trip();

        $html = Livewire::test(Bookings::class)->html();

        $this->assertStringContainsString('class="hidden size-3 shrink-0 transition sm:inline-block', $html);
        // Still sortable — the heading is the button, not the arrow.
        $this->assertStringContainsString('sortBy(\'from_date\')', $html);
    }

    /**
     * The actions column is only as wide as the word "Actions", so a
     * start-aligned button sat in the middle of the row's trailing edge with
     * empty cell beside it. Both the heading and the dots are pinned to the
     * edge — the logical one, so it mirrors under RTL.
     */
    public function test_the_actions_column_sits_at_the_trailing_edge(): void
    {
        $this->trip();

        $html = Livewire::test(Bookings::class)->html();

        $this->assertStringContainsString(
            '<th class="sticky end-0 z-20 bg-chrome-50 px-2 py-2 text-end',
            $html,
        );
        $this->assertStringContainsString(
            '<td class="sticky end-0 z-10 bg-white px-2 py-2 text-end',
            $html,
        );
    }

    /**
     * Only the screen is reordered. Exports read the service's own column
     * list, which the office's spreadsheets are built around.
     */
    public function test_the_export_column_order_is_untouched(): void
    {
        $this->assertSame(
            ['reference', 'from_date', 'to_date', 'type', 'customer', 'amount'],
            array_slice(array_keys(app(LimoQueueRows::class)->headings()), 0, 6),
        );
    }
}

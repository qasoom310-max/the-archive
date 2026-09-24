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
     * The page-size chips are desktop-only. Three hundred rows is not
     * something anyone reads on a phone, and the row of them was taking
     * space from the filters that are used there. The size itself still
     * rides in the URL, so a link set up on a laptop opens the same on both.
     */
    public function test_the_page_size_chips_are_hidden_on_a_phone(): void
    {
        $this->trip();

        $component = Livewire::test(Bookings::class);

        $this->assertStringContainsString(
            '<div class="hidden items-center gap-1 pb-1 sm:flex">',
            $component->html(),
        );

        // Hidden, not removed: a phone opens on 25 and pages through.
        $component->assertSet('perPage', 25);
    }

    /**
     * A phone reaches a row action only through the menu — the car and driver
     * buttons in their own columns are off the screen at that width — so the
     * menu carries Assign there instead of the booking's shared details, which
     * are a desk job and still reachable from Open full booking on any screen.
     */
    public function test_the_menu_offers_assign_on_a_phone_and_edit_on_a_laptop(): void
    {
        $this->trip();

        $html = Livewire::test(Bookings::class)->html();

        // Both are in the markup; CSS decides which width shows which.
        $this->assertStringContainsString('Assign car &amp; driver', $html);
        $this->assertStringContainsString('Edit booking details', $html);

        // Assign is the phone's; editing waits for a wider screen.
        $this->assertStringContainsString('flex w-full sm:hidden', $html);
        $this->assertStringContainsString('hidden w-full sm:flex', $html);

        // Both still do what they did — the menu changed, not the actions.
        $this->assertStringContainsString('openAssign(', $html);
        $this->assertStringContainsString('openEdit(', $html);
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

    /**
     * Received / Balance / Driver / Added by / Comments / Booked time used to
     * wait for `xl` (1280px) or even `2xl` (1536px) — far wider than a normal
     * work laptop's browser window clears, so they were effectively hidden
     * from almost anyone not on an ultra-wide monitor. `lg` (1024px) is the
     * one width tier every one of these secondary columns now shares.
     */
    public function test_the_secondary_columns_show_from_a_normal_laptop_width(): void
    {
        $this->trip();

        $html = Livewire::test(Bookings::class)->html();

        $this->assertStringContainsString('lg:table-cell', $html);
        $this->assertStringNotContainsString('xl:table-cell', $html);
        $this->assertStringNotContainsString('2xl:table-cell', $html);
    }

    /**
     * Who took the booking is asked for across the desk all day, and it was
     * waiting for a laptop. It reads on a phone now, heading and value both.
     */
    public function test_added_by_reads_on_a_phone(): void
    {
        $this->trip();
        LimoBooking::query()->firstOrFail()->forceFill(['prepared_by' => 'qassim'])->saveQuietly();

        $html = Livewire::test(Bookings::class)->html();

        $heading = $this->cellAround($html, 'Sort by Added by');
        $this->assertStringNotContainsString('hidden', $heading, 'The Added by heading is still hidden on a phone.');

        // The value sits in the same column and has its own class, so being
        // told about the heading alone would not mean it is readable.
        $this->assertMatchesRegularExpression(
            '/<td class="px-2 py-2 text-chrome-900">\s*qassim\s*<\/td>/',
            $html,
            'The Added by value is still hidden on a phone.',
        );
    }

    /**
     * The heading's width class comes from the $vis map and each body cell
     * carries its own — two halves of one column, written in two places. If
     * they disagree the table misaligns at that width: every cell after the
     * odd one out sits under the wrong heading, which reads as wrong data
     * rather than as a layout fault.
     */
    public function test_every_columns_heading_and_cells_appear_at_the_same_width(): void
    {
        $this->trip();

        $html = Livewire::test(Bookings::class)->html();

        $head = $this->widthTiers($html, '<th');
        $body = $this->widthTiers($html, '<td');

        $this->assertNotSame([], $head);
        $this->assertSame($head, $body, 'A heading and its cells appear at different screen widths.');
    }

    /** The class attribute of the element containing the given marker. */
    private function cellAround(string $html, string $marker): string
    {
        $at = strpos($html, $marker);
        $this->assertNotFalse($at, "Not found in the page: {$marker}");

        $open = strrpos(substr($html, 0, $at), '<th');
        $this->assertNotFalse($open);

        return substr($html, (int) $open, $at - (int) $open);
    }

    /**
     * The width tier of every cell of one table row, in order: 'sm', 'md',
     * 'lg' or '' for one that is always on.
     *
     * @return list<string>
     */
    private function widthTiers(string $html, string $tag): array
    {
        $table = (string) strstr($html, '<table id="limo-queue"');
        $row = $tag === '<th'
            ? (string) strstr($table, '<tr>')
            : (string) strstr($table, '<tbody');

        $end = strpos($row, '</tr>');
        $row = $end === false ? $row : substr($row, 0, $end);

        preg_match_all('/<(?:th|td)\s+class="([^"]*)"/', $row, $matches);

        return array_map(static function (string $class): string {
            foreach (['sm', 'md', 'lg'] as $tier) {
                if (str_contains($class, $tier.':table-cell')) {
                    return $tier;
                }
            }

            return '';
        }, $matches[1]);
    }

    /**
     * Which columns show has never been a permission — it is pure CSS keyed
     * to screen width, identical for every account. A Supervisor (Write,
     * never an admin) sees exactly the same set of `lg:table-cell` markers as
     * an admin; a narrower screen was the actual cause of a supervisor
     * reporting "missing" columns, not their role.
     */
    public function test_a_supervisor_sees_the_same_columns_as_an_admin(): void
    {
        $this->trip();
        $adminHtml = Livewire::test(Bookings::class)->html();

        $this->grantEveryone('limousine.booking');
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $supervisorHtml = Livewire::test(Bookings::class)->html();

        $this->assertSame(
            substr_count($adminHtml, 'lg:table-cell'),
            substr_count($supervisorHtml, 'lg:table-cell'),
        );
    }
}

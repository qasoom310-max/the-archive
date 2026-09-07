<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Calendar\AdPlanner;
use App\Erp\Calendar\EventWindow;
use App\Erp\Modules\ModuleManager;
use App\Livewire\Pages\AdCalendar;
use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Tests\TestCase;

/**
 * The ad calendar: last year's sales day by day, the windows ahead with
 * the date the ads must be live by, and the per-database rules behind it.
 */
final class AdCalendarTest extends TestCase
{
    use DatabaseMigrations;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = CarbonImmutable::create(2026, 9, 7, 9, 0, 0);
        CarbonImmutable::setTestNow($this->today);

        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function rentalSale(string $date, float $total): void
    {
        $customer = RentalCustomer::query()->firstOrCreate(['name' => 'Qassim'], ['phone' => '39000001']);
        RentalOrder::query()->create([
            'customer_id' => $customer->id,
            'start_date' => $date,
            'end_date' => $date,
            'total' => $total,
            'state' => RentalOrder::STATE_CLOSED,
        ]);
    }

    private function limoSale(string $date, float $amount, string $status = LimoBooking::STATUS_COMPLETED): void
    {
        $customer = LimoCustomer::query()->firstOrCreate(['name' => 'Dadabhai Travel'], ['type' => 'company']);
        LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => $date . ' 10:00:00',
            'fare' => $amount,
            'amount' => $amount,
            'status' => $status,
        ]);
    }

    /** One sale of the same size every Monday for the last 52 weeks — a flat "normal week". */
    private function flatYear(float $weekly = 70): void
    {
        $monday = $this->today->startOfWeek()->subWeeks(52);
        for ($i = 0; $i < 52; $i++) {
            $this->limoSale($monday->addWeeks($i)->toDateString(), $weekly);
        }
    }

    public function test_the_page_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/calendar')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/calendar')->assertOk()->assertSee('Ad calendar');
    }

    public function test_the_heatmap_sums_every_app_by_day_and_skips_cancelled_sales(): void
    {
        $this->rentalSale('2026-06-10', 40);
        $this->limoSale('2026-06-10', 25);
        $this->limoSale('2026-06-10', 999, LimoBooking::STATUS_CANCELLED);

        $heatmap = app(AdPlanner::class)->heatmap($this->today);
        $day = $this->day($heatmap, '2026-06-10');

        $this->assertEqualsWithDelta(40.0, $day['rental'], 0.001);
        $this->assertEqualsWithDelta(25.0, $day['limousine'], 0.001);
        $this->assertEqualsWithDelta(65.0, $day['total'], 0.001);
        $this->assertSame(2, $day['count']);
        $this->assertFalse($day['noData']);
    }

    public function test_days_before_the_first_sale_are_no_data_not_zero(): void
    {
        $this->limoSale('2026-05-01', 30);

        $heatmap = app(AdPlanner::class)->heatmap($this->today);

        $this->assertSame('2026-05-01', $heatmap['firstRecord']);
        $this->assertTrue($this->day($heatmap, '2026-02-10')['noData']);
        $this->assertFalse($this->day($heatmap, '2026-05-02')['noData']);
        $this->assertSame(0, $this->day($heatmap, '2026-05-02')['level']);
    }

    public function test_a_closure_reads_as_no_data_and_is_left_out_of_a_normal_week(): void
    {
        $this->flatYear(70);
        CalendarEvent::query()->create([
            'name' => 'Renovation', 'kind' => EventWindow::KIND_CLOSED, 'recurs' => false,
            'start_date' => '2026-03-02', 'end_date' => '2026-03-15',
        ]);

        $planner = app(AdPlanner::class);
        $heatmap = $planner->heatmap($this->today);

        $this->assertTrue($this->day($heatmap, '2026-03-10')['noData']);
        $this->assertEqualsWithDelta(70.0, $planner->baseline($this->today), 0.001);
    }

    public function test_eid_is_compared_with_last_years_eid_by_hijri_date(): void
    {
        // Mid-January 2027: Ramadan and Eid al-Fitr 1448 sit inside the horizon.
        $this->today = CarbonImmutable::create(2027, 1, 15, 9, 0, 0);
        CarbonImmutable::setTestNow($this->today);
        $this->flatYear(70);

        $eidAhead = $this->window('eid_fitr', $this->today->addDays(60));
        $lastEid = $eidAhead->lastYear();

        // A Hijri year back, not a Gregorian one — eleven days apart on the wall.
        $this->assertSame('2027', $eidAhead->start->format('Y'));
        $this->assertSame('2026', $lastEid->start->format('Y'));
        $this->assertNotSame($eidAhead->start->subYear()->toDateString(), $lastEid->start->toDateString());

        // Extra takings on last year's Eid — where the Hijri calendar says it was.
        for ($d = $lastEid->start; $d->lessThanOrEqualTo($lastEid->end); $d = $d->addDay()) {
            $this->limoSale($d->toDateString(), 100);
        }

        $plan = app(AdPlanner::class)->plan($this->today);
        $eid = array_values(array_filter($plan, static fn (array $i): bool => $i['key'] === 'eid_fitr'));
        $this->assertCount(1, $eid);

        $this->assertSame($lastEid->start->toDateString(), $eid[0]['lastYearStart']);
        $this->assertFalse($eid[0]['noData']);
        $this->assertGreaterThanOrEqual(400.0, (float) $eid[0]['lastYearRevenue']);
        $this->assertGreaterThan(2.0, (float) $eid[0]['uplift']);
        $this->assertSame('GCC', $eid[0]['country']);
    }

    public function test_launch_by_dates_follow_the_lead_days_and_flag_overdue(): void
    {
        $this->flatYear(70);
        CalendarEvent::query()->create([
            'name' => 'F1 weekend', 'kind' => EventWindow::KIND_CUSTOM, 'recurs' => true,
            'start_date' => $this->today->addDays(5)->toDateString(),
            'end_date' => $this->today->addDays(7)->toDateString(),
        ]);

        $planner = app(AdPlanner::class);
        $planner->setLeadDays(['rental' => 14, 'limousine' => 3]);

        $plan = $planner->plan($this->today);
        $f1 = array_values(array_filter($plan, static fn (array $i): bool => $i['label'] === 'F1 weekend'));
        $this->assertCount(1, $f1);

        $item = $f1[0];
        $this->assertSame($this->today->addDays(5)->toDateString(), $item['start']);
        $this->assertSame($this->today->subDays(9)->toDateString(), $item['launch']['rental']['by']);
        $this->assertSame('overdue', $item['launch']['rental']['status']);
        $this->assertSame($this->today->addDays(2)->toDateString(), $item['launch']['limousine']['by']);
        $this->assertSame('now', $item['launch']['limousine']['status']);
        $this->assertSame('overdue', $item['status']);
        $this->assertSame(5, $item['daysUntil']);
    }

    public function test_last_years_figure_for_an_owner_window_uses_the_same_dates_a_year_earlier(): void
    {
        $this->flatYear(70);
        $start = $this->today->addDays(30);
        CalendarEvent::query()->create([
            'name' => 'Wedding season', 'kind' => EventWindow::KIND_CUSTOM, 'recurs' => true,
            'start_date' => $start->toDateString(), 'end_date' => $start->addDays(6)->toDateString(),
        ]);
        for ($d = $start->subYear(); $d->lessThanOrEqualTo($start->subYear()->addDays(6)); $d = $d->addDay()) {
            $this->limoSale($d->toDateString(), 50);
        }

        $plan = app(AdPlanner::class)->plan($this->today);
        $item = array_values(array_filter($plan, static fn (array $i): bool => $i['label'] === 'Wedding season'))[0];

        $this->assertSame($start->subYear()->toDateString(), $item['lastYearStart']);
        $this->assertFalse($item['noData']);
        // 7 × 50 plus whatever flat Monday fell inside — well above a 70 week.
        $this->assertGreaterThanOrEqual(350.0, (float) $item['lastYearRevenue']);
        $this->assertGreaterThan(1.0, (float) $item['uplift']);
    }

    public function test_a_window_before_the_first_sale_is_no_data_rather_than_a_dead_week(): void
    {
        $this->limoSale($this->today->subDays(10)->toDateString(), 70);
        CalendarEvent::query()->create([
            'name' => 'Boat show', 'kind' => EventWindow::KIND_CUSTOM, 'recurs' => true,
            'start_date' => $this->today->addDays(20)->toDateString(), 'end_date' => $this->today->addDays(22)->toDateString(),
        ]);

        $plan = app(AdPlanner::class)->plan($this->today);
        $item = array_values(array_filter($plan, static fn (array $i): bool => $i['label'] === 'Boat show'))[0];

        $this->assertTrue($item['noData']);
        $this->assertNull($item['lastYearRevenue']);
        $this->assertNull($item['uplift']);
    }

    public function test_unusual_weeks_with_no_known_window_are_surfaced(): void
    {
        $this->flatYear(70);
        $planner = app(AdPlanner::class);
        $planner->setMarkets(['BH']);

        // A quiet week 20 weeks back, chosen so no known window explains it.
        $peakWeek = null;
        for ($back = 10; $back < 45; $back++) {
            $monday = $this->today->startOfWeek()->subWeeks($back);
            $explained = false;
            foreach ($planner->events($monday, $monday->addDays(6)) as $event) {
                if ($event->overlaps($monday, $monday->addDays(6))) {
                    $explained = true;
                    break;
                }
            }
            if (! $explained) {
                $peakWeek = $monday;
                break;
            }
        }
        $this->assertNotNull($peakWeek);

        $this->limoSale($peakWeek->addDays(2)->toDateString(), 700);

        $unnamed = $planner->unnamed($this->today);
        $peaks = array_values(array_filter($unnamed, static fn (array $w): bool => $w['type'] === 'peak'));

        $this->assertCount(1, $peaks);
        $this->assertSame($peakWeek->toDateString(), $peaks[0]['start']);
        $this->assertGreaterThanOrEqual(AdPlanner::PEAK_RATIO, $peaks[0]['ratio']);
    }

    public function test_rules_are_saved_for_this_database(): void
    {
        Livewire::test(AdCalendar::class)
            ->set('leadDays.rental', '21')
            ->set('leadDays.limousine', '10')
            ->set('markets', ['BH', 'KW'])
            ->call('saveSettings')
            ->assertHasNoErrors();

        $planner = app(AdPlanner::class);
        $this->assertSame(21, $planner->leadDays()['rental']);
        $this->assertSame(10, $planner->leadDays()['limousine']);
        $this->assertSame(['BH', 'KW'], $planner->markets());
    }

    public function test_rules_refuse_an_empty_market_list_and_silly_lead_days(): void
    {
        Livewire::test(AdCalendar::class)
            ->set('markets', [])
            ->call('saveSettings')
            ->assertHasErrors(['markets']);

        Livewire::test(AdCalendar::class)
            ->set('leadDays.rental', '900')
            ->call('saveSettings')
            ->assertHasErrors(['leadDays.rental']);
    }

    public function test_an_owner_event_is_added_edited_and_removed_from_the_page(): void
    {
        $component = Livewire::test(AdCalendar::class)
            ->call('openEvent')
            ->set('eventName', 'F1 weekend')
            ->set('eventStart', '2026-03-13')
            ->set('eventEnd', '2026-03-15')
            ->call('saveEvent')
            ->assertHasNoErrors();

        $event = CalendarEvent::query()->firstOrFail();
        $this->assertSame('F1 weekend', $event->name);
        $this->assertTrue($event->recurs);

        $component->call('openEvent', $event->id)
            ->assertSet('eventName', 'F1 weekend')
            ->set('eventEnd', '2026-03-16')
            ->call('saveEvent')
            ->assertHasNoErrors();
        $this->assertSame('2026-03-16', $event->fresh()?->end_date->toDateString());

        $component->call('deleteEvent', $event->id);
        $this->assertSame(0, CalendarEvent::query()->count());
    }

    public function test_an_event_cannot_end_before_it_starts(): void
    {
        Livewire::test(AdCalendar::class)
            ->call('openEvent')
            ->set('eventName', 'Backwards')
            ->set('eventStart', '2026-03-15')
            ->set('eventEnd', '2026-03-13')
            ->call('saveEvent')
            ->assertHasErrors(['eventEnd']);
    }

    public function test_a_recurring_owner_event_shows_up_every_year(): void
    {
        CalendarEvent::query()->create([
            'name' => 'Wedding season', 'kind' => EventWindow::KIND_CUSTOM, 'recurs' => true,
            'start_date' => '2024-10-01', 'end_date' => '2024-11-15',
        ]);

        $windows = CalendarEvent::windowsBetween(
            CarbonImmutable::create(2026, 1, 1, 0, 0, 0),
            CarbonImmutable::create(2026, 12, 31, 0, 0, 0),
        );

        $this->assertCount(1, $windows);
        $this->assertSame('2026-10-01', $windows[0]->start->toDateString());
        $this->assertSame('2026-11-15', $windows[0]->end->toDateString());
    }

    /**
     * @param  array<string, mixed>  $heatmap
     * @return array<string, mixed>
     */
    private function day(array $heatmap, string $date): array
    {
        /** @var list<array<string, mixed>> $months */
        $months = $heatmap['months'];
        foreach ($months as $month) {
            /** @var list<array<string, mixed>> $days */
            $days = $month['days'];
            foreach ($days as $day) {
                if ($day['date'] === $date) {
                    return $day;
                }
            }
        }

        $this->fail("Day {$date} not in heatmap.");
    }

    private function window(string $key, CarbonImmutable $around): EventWindow
    {
        foreach (app(AdPlanner::class)->events($around->subDays(400), $around->addDays(400)) as $event) {
            if ($event->key === $key && $event->start->greaterThan($around->subDays(200))) {
                return $event;
            }
        }

        $this->fail("No {$key} window found.");
    }
}

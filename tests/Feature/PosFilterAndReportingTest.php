<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Views\DatePreset;
use App\Erp\Views\FilterDef;
use App\Erp\Views\ViewResolver;
use App\Livewire\Views\ListView;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosReporting;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * Pins three pieces of the engine's new filter machinery + the POS-side
 * reporting page that consumes it:
 *
 *   1. ViewArch parses `filters[]` into FilterDef objects, ignoring
 *      malformed and unknown-preset entries silently.
 *   2. ListView applies the active filter to BOTH the paginated query
 *      and the aggregate query (so footer totals scope to the filter).
 *   3. PosReporting computes KPIs only from finalised orders and lets
 *      the preset switcher swap the window without remounting the URL.
 */
final class PosFilterAndReportingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function installPos(): void
    {
        app(ModuleManager::class)->install('pos');
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 50.0,
            'opened_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function makeOrder(int $sessionId, Carbon $orderedAt, float $total, array $extra = []): PosOrder
    {
        return PosOrder::query()->create([
            'pos_session_id' => $sessionId,
            'reference' => 'POS/' . uniqid(),
            'state' => OrderState::Done,
            'total' => $total,
            'paid_total' => $total,
            'ordered_at' => $orderedAt,
            ...$extra,
        ]);
    }

    // ───────────────────────── DatePreset ──────────────────────────

    public function test_date_preset_resolves_known_names_and_rejects_unknown(): void
    {
        $today = DatePreset::range('today');
        $this->assertNotNull($today);
        $this->assertTrue($today[0]->isSameDay(Carbon::today()));
        $this->assertTrue($today[1]->isSameDay(Carbon::today()));

        $yesterday = DatePreset::range('yesterday');
        $this->assertNotNull($yesterday);
        $this->assertTrue($yesterday[0]->isSameDay(Carbon::yesterday()));

        $this->assertNull(DatePreset::range('last_century'));
        $this->assertFalse(DatePreset::isValid('nonsense'));
        $this->assertTrue(DatePreset::isValid('this_week'));
    }

    // ───────────────────────── ViewArch ────────────────────────────

    public function test_view_arch_parses_filters_and_drops_malformed_entries(): void
    {
        $this->installPos();
        $arch = app(ViewResolver::class)->arch('pos.order', 'list');

        $this->assertCount(4, $arch->filters);
        $this->assertContainsOnlyInstancesOf(FilterDef::class, $arch->filters);

        $today = $arch->filters[0];
        $this->assertSame('today', $today->name);
        $this->assertSame("Today's Sales", $today->label);
        $this->assertSame('ordered_at', $today->field);
        $this->assertSame('today', $today->preset);
    }

    // ───────────────────────── ListView ────────────────────────────

    public function test_list_view_filter_scopes_rows_and_footer_total(): void
    {
        $this->installPos();
        $session = $this->openSession();

        // 2 orders today (totals 10 + 5 = 15) + 1 order last week (total 50).
        $this->makeOrder($session->id, Carbon::today()->addHours(9), 10.00);
        $this->makeOrder($session->id, Carbon::today()->addHours(14), 5.00);
        $this->makeOrder($session->id, Carbon::today()->subDays(8), 50.00);

        // No filter → 3 rows, total 65.
        $c = Livewire::test(ListView::class, [
            'model' => PosOrder::class,
            'modelKey' => 'pos.order',
            'title' => 'POS Orders',
        ]);
        $c->assertViewHas('aggregates', fn (array $a): bool => abs(($a['total'] ?? 0.0) - 65.0) < 0.01);

        // Apply 'today' preset → 2 rows, total 15. Aggregates must follow the filter.
        $c->call('applyFilterPreset', 'today')
            ->assertSet('filter', 'today')
            ->assertViewHas('aggregates', fn (array $a): bool => abs(($a['total'] ?? 0.0) - 15.0) < 0.01);

        // Toggle off by clicking the active preset → back to 65.
        $c->call('applyFilterPreset', 'today')
            ->assertSet('filter', '')
            ->assertViewHas('aggregates', fn (array $a): bool => abs(($a['total'] ?? 0.0) - 65.0) < 0.01);
    }

    public function test_list_view_ignores_unknown_filter_preset(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $this->makeOrder($session->id, Carbon::today(), 12.00);

        // Direct property set (simulates URL tampering ?filter=bogus).
        Livewire::test(ListView::class, [
            'model' => PosOrder::class,
            'modelKey' => 'pos.order',
        ])
            ->set('filter', 'bogus')
            ->assertViewHas('aggregates', fn (array $a): bool => abs(($a['total'] ?? 0.0) - 12.0) < 0.01); // no scoping
    }

    public function test_apply_filter_preset_with_empty_string_clears(): void
    {
        $this->installPos();
        Livewire::test(ListView::class, ['model' => PosOrder::class, 'modelKey' => 'pos.order'])
            ->call('applyFilterPreset', 'today')
            ->assertSet('filter', 'today')
            ->call('applyFilterPreset', '')
            ->assertSet('filter', '');
    }

    // ───────────────────── Custom date range ───────────────────────

    public function test_custom_date_range_scopes_rows_and_aggregates(): void
    {
        $this->installPos();
        $session = $this->openSession();

        // Three orders spread across April 1–10; pick April 5–7 only.
        $this->makeOrder($session->id, Carbon::parse('2026-04-02 10:00:00'), 11.00);
        $this->makeOrder($session->id, Carbon::parse('2026-04-06 10:00:00'), 22.00);
        $this->makeOrder($session->id, Carbon::parse('2026-04-09 10:00:00'), 33.00);

        Livewire::test(ListView::class, [
            'model' => PosOrder::class,
            'modelKey' => 'pos.order',
        ])
            ->set('customFrom', '2026-04-05')
            ->set('customTo', '2026-04-07')
            ->call('applyCustomRange')
            ->assertSet('filter', 'custom')
            ->assertViewHas('aggregates', fn (array $a): bool => abs(($a['total'] ?? 0.0) - 22.0) < 0.01);
    }

    public function test_custom_range_swaps_reversed_bounds(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $this->makeOrder($session->id, Carbon::parse('2026-04-06 10:00:00'), 22.00);

        Livewire::test(ListView::class, ['model' => PosOrder::class, 'modelKey' => 'pos.order'])
            ->set('customFrom', '2026-04-10') // typed wrong way around
            ->set('customTo',   '2026-04-01')
            ->call('applyCustomRange')
            ->assertSet('customFrom', '2026-04-01') // swapped + normalised
            ->assertSet('customTo',   '2026-04-10')
            ->assertSet('filter', 'custom')
            ->assertViewHas('aggregates', fn (array $a): bool => abs(($a['total'] ?? 0.0) - 22.0) < 0.01);
    }

    public function test_custom_range_missing_bound_is_a_noop(): void
    {
        $this->installPos();
        Livewire::test(ListView::class, ['model' => PosOrder::class, 'modelKey' => 'pos.order'])
            ->set('customFrom', '2026-04-01')
            ->set('customTo', '')               // blank
            ->call('applyCustomRange')
            ->assertSet('filter', '');          // unchanged
    }

    public function test_custom_range_invalid_date_is_a_noop(): void
    {
        $this->installPos();
        Livewire::test(ListView::class, ['model' => PosOrder::class, 'modelKey' => 'pos.order'])
            ->set('customFrom', 'not-a-date')
            ->set('customTo',   '2026-04-10')
            ->call('applyCustomRange')
            ->assertSet('filter', '');
    }

    public function test_switching_to_preset_clears_leftover_custom_range(): void
    {
        $this->installPos();
        Livewire::test(ListView::class, ['model' => PosOrder::class, 'modelKey' => 'pos.order'])
            ->set('customFrom', '2026-04-01')
            ->set('customTo',   '2026-04-10')
            ->call('applyCustomRange')
            ->assertSet('filter', 'custom')
            ->call('applyFilterPreset', 'today')
            ->assertSet('filter', 'today')
            ->assertSet('customFrom', '')        // wiped
            ->assertSet('customTo', '');
    }

    public function test_arch_without_custom_date_field_ignores_apply_custom_range(): void
    {
        // demo.ticket has filters disabled by default → no custom date field.
        // Calling applyCustomRange should be a silent no-op even if the
        // user POSTs hand-crafted URL params at it.
        Livewire::test(ListView::class, [
            'model' => \App\Models\Demo\DemoTicket::class,
            'modelKey' => 'demo.ticket',
        ])
            ->set('customFrom', '2026-04-01')
            ->set('customTo',   '2026-04-10')
            ->call('applyCustomRange')
            ->assertSet('filter', ''); // arch hasn't opted in
    }

    // ─────────────────────── PosReporting ──────────────────────────

    public function test_reporting_page_defaults_to_today_preset(): void
    {
        $this->installPos();

        Livewire::test(PosReporting::class)
            ->assertOk()
            ->assertSet('preset', 'today')
            ->assertViewHas('activePreset', 'today');
    }

    public function test_reporting_kpis_count_only_finalized_orders_in_window(): void
    {
        $this->installPos();
        $session = $this->openSession();

        // Today: 2 done + 1 draft + 1 cancelled.
        $this->makeOrder($session->id, Carbon::today()->addHours(8), 30.00);
        $this->makeOrder($session->id, Carbon::today()->addHours(12), 20.00);
        $this->makeOrder($session->id, Carbon::today()->addHours(14), 99.00, ['state' => OrderState::Draft]);
        $this->makeOrder($session->id, Carbon::today()->addHours(15), 77.00, ['state' => OrderState::Cancelled]);

        // Yesterday — must NOT count toward "today" KPIs.
        $this->makeOrder($session->id, Carbon::yesterday()->addHours(10), 500.00);

        Livewire::test(PosReporting::class)
            ->assertViewHas('kpis', function (array $kpis): bool {
                return abs($kpis['revenue'] - 50.0) < 0.01      // 30 + 20
                    && $kpis['orders'] === 2                    // drafts/cancels excluded
                    && abs($kpis['average'] - 25.0) < 0.01;     // 50 / 2
            });
    }

    public function test_reporting_preset_switch_updates_kpis(): void
    {
        $this->installPos();
        $session = $this->openSession();

        $this->makeOrder($session->id, Carbon::today()->addHours(9), 10.00);
        $this->makeOrder($session->id, Carbon::yesterday()->addHours(9), 40.00);

        Livewire::test(PosReporting::class)
            ->assertViewHas('kpis', fn (array $k): bool => abs($k['revenue'] - 10.0) < 0.01)
            ->call('setPreset', 'yesterday')
            ->assertSet('preset', 'yesterday')
            ->assertViewHas('kpis', fn (array $k): bool => abs($k['revenue'] - 40.0) < 0.01);
    }

    public function test_reporting_aov_is_zero_when_no_orders_in_window(): void
    {
        $this->installPos();
        // No orders at all — division-by-zero guard.
        Livewire::test(PosReporting::class)
            ->assertViewHas('kpis', function (array $kpis): bool {
                return $kpis['revenue'] === 0.0
                    && $kpis['orders'] === 0
                    && $kpis['average'] === 0.0;
            });
    }

    public function test_reporting_rejects_unknown_preset_on_mount(): void
    {
        $this->installPos();
        Livewire::test(PosReporting::class, ['preset' => 'last_century'])
            ->assertSet('preset', 'today'); // sanitised
    }

    public function test_reporting_ignores_unknown_preset_on_switch(): void
    {
        $this->installPos();
        Livewire::test(PosReporting::class)
            ->call('setPreset', 'today')
            ->call('setPreset', 'nonsense')
            ->assertSet('preset', 'today'); // unchanged
    }

    public function test_reporting_requires_pos_order_read(): void
    {
        $this->installPos();

        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(PosReporting::class)->assertForbidden();
    }
}

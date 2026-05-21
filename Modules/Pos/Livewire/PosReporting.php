<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Views\DatePreset;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Models\PosOrder;

/**
 * POS Reporting dashboard — opens pre-filtered to "today" so the
 * manager sees today's revenue immediately. The preset switcher
 * recomputes three KPIs (revenue, order count, average order value)
 * and the embedded orders list below scopes to the same preset via
 * the engine's filter mechanism.
 *
 * Why a dedicated page instead of just the filtered list:
 *   - KPI cards condense the answer ("Today's revenue is 1,234 BD")
 *     into one glance; the table is the supporting detail.
 *   - The preset switcher is the single source of truth — both the
 *     KPIs and the table below recompute together. (The table's
 *     own chip row would let them drift; we hide it via `?filter=…`
 *     so the page stays in sync.)
 */
#[Layout('components.layouts.app')]
#[Title('POS Reporting')]
final class PosReporting extends Component
{
    /**
     * Date preset driving both the KPIs and the embedded list. URL-bound
     * so deep links work (`/app/pos/reporting?preset=this_week`).
     */
    #[Url]
    public string $preset = 'today';

    public function mount(): void
    {
        app(AccessControl::class)->authorize(
            Auth::user(),
            'pos.order',
            Permission::Read,
        );

        // Defend against URL tampering / removed presets — fall back to today.
        if (! DatePreset::isValid($this->preset)) {
            $this->preset = 'today';
        }
    }

    public function setPreset(string $preset): void
    {
        if (! DatePreset::isValid($preset)) {
            return;
        }

        $this->preset = $preset;
    }

    /**
     * Compute the three KPIs for the active preset in one pass. Aggregates
     * scope only to finalised orders (state = Done) — drafts and cancels
     * aren't revenue.
     *
     * @return array{revenue: float, orders: int, average: float}
     */
    private function kpis(): array
    {
        $range = DatePreset::range($this->preset);

        if ($range === null) {
            return ['revenue' => 0.0, 'orders' => 0, 'average' => 0.0];
        }

        [$start, $end] = $range;

        $query = PosOrder::query()
            ->where('state', OrderState::Done)
            ->whereBetween('ordered_at', [$start, $end]);

        $revenue = (float) $query->clone()->sum('total');
        $orders = (int) $query->clone()->count();
        $average = $orders > 0 ? round($revenue / $orders, 2) : 0.0;

        return ['revenue' => $revenue, 'orders' => $orders, 'average' => $average];
    }

    public function render(): View
    {
        return view('pos::reporting', [
            'kpis' => $this->kpis(),
            'presets' => DatePreset::available(),
            'activePreset' => $this->preset,
        ]);
    }
}

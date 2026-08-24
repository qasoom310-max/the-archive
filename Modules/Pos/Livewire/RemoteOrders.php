<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Enums\FulfillmentStatus;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SalesChannel;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Services\PosSessionManager;

/**
 * Remote / delivery sales dashboard: the fulfillment queue for orders taken
 * over phone / WhatsApp / delivery. Each row shows the customer + address +
 * total and advances one step along the pipeline (new → packed → out for
 * delivery → delivered). Perfume / retail feature-gated to Remote sales.
 */
#[Layout('components.layouts.app')]
#[Title('Remote / delivery sales')]
final class RemoteOrders extends Component
{
    /** Seconds a bulk collect may spend before handing the rest back. */
    private const BULK_BUDGET_SECONDS = 20.0;

    /** active | new | packed | out_for_delivery | delivered | all */
    public string $filter = 'active';

    /**
     * Period the delivery money totals cover: month (this month) | last | all.
     * Defaults to THIS MONTH — an all-time running total grows forever and
     * can't answer "what is delivery costing me now".
     */
    public string $period = 'month';

    /**
     * Order ids ticked for a bulk action — a delivery round comes back with a
     * dozen orders to collect or move on at once.
     *
     * @var list<int>
     */
    public array $selectedOrders = [];

    public function mount(): void
    {
        // Remote sales off in THIS database (the feature toggled off, or — the
        // real trap — a stale wire:navigate/bookmark to this URL, or a request
        // that resolved against the wrong database). Send the user to the POS
        // home instead of a jarring "404 page not found": a disabled feature is
        // not a broken page.
        if (! Features::enabled(Feature::RemoteSales)) {
            $this->redirect(url('/app/pos'), navigate: true);

            return;
        }

        $this->guard(Permission::Read);
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', $permission);
    }

    /** Switch the period the delivery money totals cover. */
    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, ['month', 'last', 'all'], true) ? $period : 'month';
    }

    /**
     * Label + date window for the selected period. `null` window = all time.
     *
     * @return array{0: string, 1: ?Carbon, 2: ?Carbon}
     */
    private function periodWindow(): array
    {
        $now = Carbon::now();

        return match ($this->period) {
            'last' => [
                __('Last month'),
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'all' => [__('All time'), null, null],
            default => [__('This month'), $now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    /** Set / update a remote order's delivery reference (courier number). */
    public function setDeliveryReference(int $orderId, string $reference): void
    {
        $this->guard(Permission::Write);

        $order = PosOrder::query()
            ->where('channel', SalesChannel::Remote->value)
            ->find($orderId);

        if ($order === null) {
            return;
        }

        $order->delivery_reference = trim($reference) !== '' ? trim($reference) : null;
        $order->save();
    }

    /** Move a remote order one step along the delivery pipeline. */
    public function advance(int $orderId): void
    {
        $this->guard(Permission::Write);

        $order = PosOrder::query()
            ->where('channel', SalesChannel::Remote->value)
            ->find($orderId);

        $order?->advanceFulfillment();
    }

    /**
     * Collect the money on a pay-on-delivery order — records the outstanding
     * amount against the default (cash) payment method and books the sale.
     */
    public function collectPayment(int $orderId): void
    {
        $this->guard(Permission::Write);

        $order = PosOrder::query()
            ->where('channel', SalesChannel::Remote->value)
            ->find($orderId);

        if ($order === null || $order->isPaid()) {
            return;
        }

        $method = PosPaymentMethod::query()
            ->where('active', true)
            ->orderByDesc('is_cash')
            ->orderBy('sequence')
            ->first();

        if ($method === null) {
            return;
        }

        $order->collectPayment($method);
    }

    /**
     * Collect the money on every ticked order at once. A delivery round comes
     * back with a dozen collected orders; clicking Collect on each in turn is
     * the same action a dozen times.
     */
    public function collectSelected(): void
    {
        $this->guard(Permission::Write);

        $ids = array_map('intval', $this->selectedOrders);
        if ($ids === []) {
            return;
        }

        // Collecting fires the paid-order automations, and one of those renders
        // a receipt image (PDF → PNG) for the customer's WhatsApp. A dozen
        // orders is a dozen renders in one request, which ran past the web
        // server's timeout and died half-way with nothing said about where it
        // stopped. Work to a budget instead: each order is committed on its own,
        // so what got done stays done, and the rest stay ticked for one more press.
        $deadline = microtime(true) + self::BULK_BUDGET_SECONDS;

        $collected = 0;
        $remaining = [];

        foreach ($ids as $id) {
            $before = PosOrder::query()->find($id);
            if ($before === null || $before->isPaid()) {
                continue;   // already collected — never double-charge
            }

            if (microtime(true) >= $deadline) {
                $remaining[] = $id;

                continue;
            }

            $this->collectPayment($id);
            $collected++;
        }

        $this->selectedOrders = $remaining;

        if ($remaining !== []) {
            session()->flash('remote_status', __(':count collected, :remaining still to go — press Collect again.', [
                'count' => $collected,
                'remaining' => count($remaining),
            ]));

            return;
        }

        session()->flash('remote_status', trans_choice(
            '{1}Collected :count order.|[2,*]Collected :count orders.',
            $collected,
            ['count' => $collected],
        ));
    }

    /** Move every ticked order one step along the delivery pipeline. */
    public function advanceSelected(): void
    {
        $this->guard(Permission::Write);

        $ids = array_map('intval', $this->selectedOrders);
        if ($ids === []) {
            return;
        }

        foreach ($ids as $id) {
            $this->advance($id);
        }

        $count = count($ids);
        $this->selectedOrders = [];
        session()->flash('remote_status', trans_choice(
            '{1}Moved :count order forward.|[2,*]Moved :count orders forward.',
            $count,
            ['count' => $count],
        ));
    }

    /** Tick every order currently listed. */
    public function selectAllShown(): void
    {
        $this->selectedOrders = $this->visibleQuery()->limit(200)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * The orders the current tab is showing — shared by render() and
     * "select all" so the button can never tick something off-screen.
     *
     * @return \Illuminate\Database\Eloquent\Builder<PosOrder>
     */
    private function visibleQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = PosOrder::query()
            ->where('channel', SalesChannel::Remote->value)
            ->where('state', OrderState::Done);

        return match ($this->filter) {
            'active' => $query->whereIn('fulfillment_status', ['new', 'packed', 'out_for_delivery'])->oldest('ordered_at'),
            'unpaid' => $query->whereRaw('paid_total < total - 0.001')->oldest('ordered_at'),
            'all' => $query->latest('ordered_at'),
            default => $query->where('fulfillment_status', $this->filter)->latest('ordered_at'),
        };
    }

    public function clearSelection(): void
    {
        $this->selectedOrders = [];
    }

    public function render(): View
    {
        $done = fn () => PosOrder::query()
            ->where('channel', SalesChannel::Remote->value)
            ->where('state', OrderState::Done);

        // Same query, narrowed to the selected period (all time = no window).
        [$periodLabel, $from, $to] = $this->periodWindow();
        $inPeriod = fn () => $from === null
            ? $done()
            : $done()->whereBetween('ordered_at', [$from, $to]);

        // Per-status counts for the filter tabs.
        $counts = [];
        foreach (FulfillmentStatus::cases() as $status) {
            $counts[$status->value] = $done()->where('fulfillment_status', $status->value)->count();
        }
        $active = ['new', 'packed', 'out_for_delivery'];
        $activeCount = ($counts['new'] ?? 0) + ($counts['packed'] ?? 0) + ($counts['out_for_delivery'] ?? 0);
        // Outstanding cash: pay-on-delivery orders not yet collected.
        $unpaidCount = $done()->whereRaw('paid_total < total - 0.001')->count();

        $query = $this->visibleQuery()->with('partner');

        // "New remote order" jumps to the register with the Remote channel
        // pre-selected. If no session is open, land on the POS home to open one.
        $active = app(PosSessionManager::class)->getActiveSession();
        $startUrl = $active !== null
            ? url('/app/pos/session/' . $active->id . '/terminal?channel=remote')
            : url('/app/pos');

        return view('pos::remote-orders', [
            'orders' => $query->withCount('lines')->limit(200)->get(),
            'counts' => $counts,
            'activeCount' => $activeCount,
            'unpaidCount' => $unpaidCount,
            // Delivery money for the selected period (default: this month) —
            // an all-time running total can't answer "what is delivery costing
            // me now".
            // Rounded: summing floats leaves dust (1.1 + 2.2 = 3.3000000000000003).
            'deliveryCostTotal' => round((float) $inPeriod()->sum('delivery_fee'), 3),
            'deliveryChargeTotal' => round((float) $inPeriod()->sum('delivery_charge'), 3),
            'periodLabel' => $periodLabel,
            'period' => $this->period,
            // What the ticked orders are worth to collect — so the cashier can
            // sanity-check the round's cash before committing it in one go.
            'selectedUncollected' => $this->selectedOrders === [] ? 0.0 : round((float) PosOrder::query()
                ->whereIn('id', $this->selectedOrders)
                ->whereRaw('paid_total < total - 0.001')
                ->sum('total'), 3),
            // The company keeps these out of what it collected, so the money
            // that should actually reach us is the difference.
            'selectedFees' => $this->selectedOrders === [] ? 0.0 : round((float) PosOrder::query()
                ->whereIn('id', $this->selectedOrders)
                ->whereRaw('paid_total < total - 0.001')
                ->sum('delivery_fee'), 3),
            'startUrl' => $startUrl,
            'canCreate' => app(AccessControl::class)->allows(Auth::user(), 'pos.order', Permission::Create),
            'canFulfill' => app(AccessControl::class)->allows(Auth::user(), 'pos.order', Permission::Write),
        ]);
    }
}

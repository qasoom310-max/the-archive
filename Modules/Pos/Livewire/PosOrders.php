<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPayment;
use Modules\Pos\Models\PosTable;
use Modules\Pos\Services\PosSaleEraser;

/**
 * POS Orders browser — a SierraPOS-style actionable list: search by
 * reference, filter by status, and per-row actions (split / cancel /
 * print). The legacy metadata Kanban board is still reachable via the
 * "Kanban" tab. Splitting is delegated to the shared
 * {@see SplitOrderModal} embedded at the foot of the view.
 */
#[Layout('components.layouts.app')]
#[Title('POS Orders')]
final class PosOrders extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'list';

    #[Url(except: '')]
    public string $search = '';

    /** '' = all; otherwise an OrderState backing value (draft|done|cancelled). */
    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->guard(Permission::Read);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function openSplit(int $orderId): void
    {
        $this->dispatch('open-split-order', orderId: $orderId);
    }

    /**
     * Void an open draft (the row "✕"). Only drafts can be cancelled from
     * here — a finalised sale would need a reversal of stock / accounting,
     * which is out of scope.
     */
    public function cancelOrder(int $orderId): void
    {
        $this->guard(Permission::Write);

        $order = PosOrder::query()->find($orderId);
        if ($order === null || $order->state !== OrderState::Draft) {
            return;
        }

        $order->state = OrderState::Cancelled;
        $order->save();
        $order->logChange(__('Order :ref cancelled.', ['ref' => $order->reference]));
    }

    /**
     * Permanently delete an order and everything derived from it (lines,
     * payments, journal entries) — an admin-only cleanup for TEST sales made
     * while a shop is being set up. Unlike "cancel", this leaves no record and
     * can't be undone. Stock is not restored (set it in the Stock Report).
     */
    public function deleteOrder(int $orderId): void
    {
        $this->guard(Permission::Write);
        abort_unless(Auth::user()->isAdmin(), 403);

        $order = PosOrder::query()->find($orderId);
        if ($order === null) {
            return;
        }

        app(PosSaleEraser::class)->erase($order);
    }

    /**
     * The modal moved lines off an order — re-render so totals / item
     * counts refresh (a new order row may also have appeared).
     */
    #[On('order-split')]
    public function refreshAfterSplit(): void
    {
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', $permission);
    }

    public function render(): View
    {
        $orders = PosOrder::query()
            ->with(['payments.method'])
            ->withSum('lines as units_sum', 'qty')
            ->when($this->search !== '', fn ($q) => $q->where('reference', 'like', '%' . $this->search . '%'))
            ->when($this->status !== '', fn ($q) => $q->where('state', $this->status))
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate(20);

        // `$order->table` is unusable here — it collides with Eloquent's
        // protected `$table` property — so resolve the seated tables in one
        // keyed lookup instead.
        $tableIds = $orders->getCollection()
            ->pluck('pos_table_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $tables = PosTable::query()->with('floor')->whereIn('id', $tableIds)->get()->keyBy('id');

        $rows = [];
        foreach ($orders as $order) {
            $units = (int) ($order->getAttribute('units_sum') ?? 0);

            $methods = $order->payments
                ->map(static function (PosPayment $p): ?string {
                    $method = $p->method;

                    return $method !== null ? $method->name : null;
                })
                ->filter()
                ->unique()
                ->implode(', ');

            $tableLabel = null;
            if ($order->pos_table_id !== null) {
                $seated = $tables->get($order->pos_table_id);
                if ($seated instanceof PosTable) {
                    $tableLabel = $seated->name;
                    $floorName = $seated->floor?->name;
                    if ($floorName !== null) {
                        $tableLabel = $floorName . ' / ' . $seated->name;
                    }
                }
            }

            $rows[] = [
                'id' => (int) $order->id,
                'reference' => $order->reference,
                'table' => $tableLabel,
                'type' => $order->pos_table_id !== null ? __('Dine-in') : __('Walk-in'),
                // Shown instead of table/type on a walk-in-only shop (Restaurant off).
                'cashier' => $order->processed_by,
                'time' => $order->ordered_at?->isoFormat('MMM D, h:mm A') ?? '—',
                'units' => $units,
                'payment' => $methods !== '' ? $methods : '—',
                'state' => $order->state,
                'total' => (float) $order->total,
                'splittable' => in_array($order->state, [OrderState::Draft, OrderState::Done], true) && $units >= 2,
                'cancellable' => $order->state === OrderState::Draft,
                'printable' => $order->state === OrderState::Done,
                // Proof-of-payment photo (null unless one was attached). Lets the
                // owner open the Benefit / transfer screenshot from the list.
                'proof_url' => $order->paymentProofUrl(),
            ];
        }

        return view('pos::orders', [
            'orders' => $orders,
            'rows' => $rows,
            // A walk-in-only shop (no dine-in) has no useful Table / Type, so the
            // list shows who made the sale + when instead.
            'dineIn' => Features::enabled(Feature::Restaurant),
            'canDeleteSales' => Auth::user()->isAdmin(),
            'statuses' => [
                '' => __('All statuses'),
                'draft' => __('Draft'),
                'done' => __('Paid'),
                'cancelled' => __('Cancelled'),
            ],
        ]);
    }
}

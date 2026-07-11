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
    /** active | new | packed | out_for_delivery | delivered | all */
    public string $filter = 'active';

    public function mount(): void
    {
        abort_unless(Features::enabled(Feature::RemoteSales), 404);
        $this->guard(Permission::Read);
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', $permission);
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

    public function render(): View
    {
        $done = fn () => PosOrder::query()
            ->where('channel', SalesChannel::Remote->value)
            ->where('state', OrderState::Done);

        // Per-status counts for the filter tabs.
        $counts = [];
        foreach (FulfillmentStatus::cases() as $status) {
            $counts[$status->value] = $done()->where('fulfillment_status', $status->value)->count();
        }
        $active = ['new', 'packed', 'out_for_delivery'];
        $activeCount = ($counts['new'] ?? 0) + ($counts['packed'] ?? 0) + ($counts['out_for_delivery'] ?? 0);
        // Outstanding cash: pay-on-delivery orders not yet collected.
        $unpaidCount = $done()->whereRaw('paid_total < total - 0.001')->count();

        $query = $done()->with('partner');
        if ($this->filter === 'active') {
            $query->whereIn('fulfillment_status', $active)->oldest('ordered_at');
        } elseif ($this->filter === 'unpaid') {
            $query->whereRaw('paid_total < total - 0.001')->oldest('ordered_at');
        } elseif ($this->filter === 'all') {
            $query->latest('ordered_at');
        } else {
            $query->where('fulfillment_status', $this->filter)->latest('ordered_at');
        }

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
            // Total we've spent on delivery (our cost, booked as an expense).
            'deliveryCostTotal' => (float) $done()->sum('delivery_fee'),
            'startUrl' => $startUrl,
            'canCreate' => app(AccessControl::class)->allows(Auth::user(), 'pos.order', Permission::Create),
            'canFulfill' => app(AccessControl::class)->allows(Auth::user(), 'pos.order', Permission::Write),
        ]);
    }
}

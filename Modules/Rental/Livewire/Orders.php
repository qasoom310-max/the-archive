<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Web\WebsiteOrigin;
use App\Erp\Activity\ActivityLogger;
use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Livewire\Concerns\SelectsListRows;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Services\ServiceOrderPortalClient;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Services\RentalAgreementSender;
use Modules\Rental\Services\RentalOrderRows;

/**
 * Rental orders list — one page covering "new / active / closed / all orders
 * and search by date" via status tabs and a pick-up date range filter.
 */
#[Layout('components.layouts.app')]
#[Title('Orders')]
final class Orders extends Component
{
    use GuardsModelAccess;
    use SelectsListRows;
    use WithPagination;

    /** all | draft | active | closed | cancelled */
    #[Url]
    public string $tab = 'all';

    protected function accessModelKey(): string
    {
        return 'rental.order';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    /** What the last bulk action did, shown above the list. */
    public string $bulkMessage = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    /**
     * @return list<int>
     */
    protected function currentPageIds(): array
    {
        return app(RentalOrderRows::class)->query($this->tab, $this->from, $this->to, $this->search)
            ->forPage($this->getPage(), 20)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, RentalOrder>
     */
    private function selectedOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return RentalOrder::query()
            ->whereKey(array_map('intval', $this->selected))
            ->orderBy('id')
            ->get();
    }

    /** Cancel every ticked order that is still open (frees the cars they hold). */
    public function cancelSelected(): void
    {
        $this->guardAccess(Permission::Write);

        $cancelled = [];
        foreach ($this->selectedOrders() as $order) {
            if (in_array($order->state, [RentalOrder::STATE_CLOSED, RentalOrder::STATE_CANCELLED], true)) {
                continue;
            }
            $order->cancelOrder();
            app(ActivityLogger::class)->logFor($order, 'cancelled');
            $cancelled[] = $order->reference;
        }

        $this->clearSelection();
        $this->bulkMessage = ($cancelled === []
            ? __('Nothing to cancel — the ticked orders are already closed or cancelled.')
            : __(':count orders cancelled.', ['count' => count($cancelled)]));
    }

    /**
     * Delete the ticked orders for good. An order that already carries money —
     * an invoice raised, or anything received — is never deleted: the receipts
     * and the books would point at nothing. Those are skipped and named, so
     * the office can cancel them instead.
     */
    public function deleteSelected(): void
    {
        $this->guardAccess(Permission::Unlink);

        $orders = $this->selectedOrders();
        $invoiced = RentalInvoice::query()
            ->whereIn('order_id', $orders->modelKeys())
            ->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $deleted = [];
        $kept = [];
        foreach ($orders as $order) {
            if (in_array((int) $order->id, $invoiced, true) || (float) $order->advance_amount > 0) {
                $kept[] = $order->reference;

                continue;
            }

            DB::transaction(function () use ($order): void {
                // Free the car first, the same way a cancel would.
                $order->cancelOrder();
                if (Schema::hasTable('rental_web_bookings') && Schema::hasColumn('rental_web_bookings', 'rental_order_id')) {
                    DB::table('rental_web_bookings')->where('rental_order_id', $order->id)->update(['rental_order_id' => null]);
                }
                $order->delete();
            });
            $deleted[] = $order->reference;
        }

        if ($deleted !== []) {
            app(ActivityLogger::class)->log('deleted', 'Rental orders', implode(', ', $deleted));
        }

        $this->clearSelection();

        $message = __(':count orders deleted.', ['count' => count($deleted)]);
        if ($kept !== []) {
            $message .= ' ' . __('Not deleted because money is recorded on them (cancel them instead): :refs', ['refs' => implode(', ', $kept)]);
        }
        $this->bulkMessage = $message;
    }

    /**
     * Orders that still owe money — unpaid or part-paid on a live / closed order
     * (matches the dashboard money box). Draft and cancelled orders are excluded.
     *
     * @param  Builder<RentalOrder>  $query
     * @return Builder<RentalOrder>
     */
    private function applyOwing(Builder $query): Builder
    {
        return $query
            ->whereIn('payment_status', [RentalOrder::PAYMENT_UNPAID, RentalOrder::PAYMENT_PARTIAL])
            ->whereIn('state', [RentalOrder::STATE_ACTIVE, RentalOrder::STATE_CLOSED]);
    }

    /* ── Online payment link (Wanaan WordPress portal → Tap) ─────────────── */

    /** The order a payment link is being raised for, or null when shut. */
    #[Locked]
    public ?int $paymentOrderId = null;

    /** What the customer is asked to pay on this link. */
    public string $paymentAmount = '';

    /** The generated public link, shown to copy to the customer. */
    public string $paymentLinkUrl = '';

    /** The quick shares offered, as a percent of the order total. */
    public const PAYMENT_SHARES = [25, 50, 100];

    /**
     * Whether links can be made in this database: the limousine portal is
     * switched on and its link table knows about rental orders.
     */
    public function portalEnabled(): bool
    {
        return Schema::hasTable('limo_portal_configuration')
            && Schema::hasColumn('limo_payment_links', 'rental_order_id')
            && LimoPortalConfiguration::current()->isConfigured();
    }

    /** An order that can still be paid online: not cancelled, something owed. */
    private function payableOrder(?int $id): ?RentalOrder
    {
        $order = $id !== null ? RentalOrder::query()->find($id) : null;

        return $order !== null && $order->state !== RentalOrder::STATE_CANCELLED && $order->balance > 0.0005
            ? $order
            : null;
    }

    /** Email the agreement PDF to the order's customer, once (as on the order page). */
    public function emailAgreement(int $orderId): void
    {
        $this->guardAccess(Permission::Write);

        $order = RentalOrder::query()->find($orderId);
        if ($order === null || $order->state === RentalOrder::STATE_CANCELLED) {
            return;
        }

        $this->bulkMessage = app(RentalAgreementSender::class)->send($order);
    }

    /** Open the dialog, pre-filled with what is still owed. */
    public function openPaymentLink(int $orderId): void
    {
        $this->guardAccess(Permission::Write);

        $order = $this->payableOrder($orderId);
        if ($order === null || ! $this->portalEnabled()) {
            return;
        }

        $this->resetValidation();
        $this->paymentOrderId = $order->id;
        $this->paymentLinkUrl = '';
        $this->paymentAmount = number_format($order->balance, 3, '.', '');
    }

    /**
     * 25% / 50% of the order TOTAL (a second 25% instalment is the same figure
     * as the first) and "Full" = the balance, each capped at the balance.
     *
     * @return list<array{percent: int, amount: float}>
     */
    public function paymentShares(): array
    {
        $order = $this->payableOrder($this->paymentOrderId);
        if ($order === null) {
            return [];
        }

        return array_values(array_map(static fn (int $percent): array => [
            'percent' => $percent,
            'amount' => $percent === 100 ? round($order->balance, 3) : round(min($order->balance, $order->total * $percent / 100), 3),
        ], self::PAYMENT_SHARES));
    }

    public function usePaymentShare(int $percent): void
    {
        foreach ($this->paymentShares() as $share) {
            if ($share['percent'] === $percent) {
                $this->paymentAmount = number_format($share['amount'], 3, '.', '');
                $this->resetValidation('paymentAmount');

                return;
            }
        }
    }

    public function closePaymentLink(): void
    {
        $this->paymentOrderId = null;
        $this->paymentAmount = '';
        $this->paymentLinkUrl = '';
    }

    /**
     * Raise the link and push it to the portal. A WordPress outage shows as a
     * message, never a 500, and the half-made link is dropped so a broken URL
     * is never handed to a customer.
     */
    public function createPaymentLink(): void
    {
        $this->guardAccess(Permission::Write);

        $order = $this->payableOrder($this->paymentOrderId);
        if ($order === null || ! $this->portalEnabled()) {
            $this->closePaymentLink();

            return;
        }

        // The balance shown at 2 decimals, typed back, is the balance.
        $typed = trim(str_replace(',', '', $this->paymentAmount));
        if (is_numeric($typed) && abs((float) $typed - $order->balance) < 0.005) {
            $this->paymentAmount = number_format($order->balance, 3, '.', '');
        }

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.001', 'max:' . max(0.001, round($order->balance, 3))],
        ], [
            'paymentAmount.max' => __('That is more than the :amount still owed on this order.', [
                'amount' => \App\Erp\Views\ValueFormat::money($order->balance),
            ]),
        ], [
            'paymentAmount' => __('Amount'),
        ]);

        $link = LimoPaymentLink::query()->create([
            'rental_order_id' => $order->id,
            'amount' => round((float) $this->paymentAmount, 3),
            'currency' => 'BHD',
            'created_by_user_id' => Auth::id(),
        ]);

        if (! app(ServiceOrderPortalClient::class)->push($link)) {
            $link->delete();
            $this->bulkMessage = __('Could not create the payment link. Check the portal settings and try again.');
            $this->closePaymentLink();

            return;
        }

        app(ActivityLogger::class)->log(
            'updated',
            __('Order :ref', ['ref' => (string) $order->reference]),
            'Payment link for ' . number_format((float) $link->amount, 3, '.', '') . ' BHD',
        );

        $this->paymentLinkUrl = (string) $link->refresh()->url;
    }

    public function render(): View
    {
        $query = app(RentalOrderRows::class)->query($this->tab, $this->from, $this->to, $this->search);

        $counts = RentalOrder::query()
            ->selectRaw('state, COUNT(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');

        $unpaidCount = $this->applyOwing(RentalOrder::query())->count();

        $user = Auth::user();
        $orders = $query->paginate(20);

        return view('rental::orders', [
            'orders' => $orders,
            // Orders made on the website, for a "Website" badge on the row.
            'fromWebsite' => WebsiteOrigin::rentalOrders(array_map(static fn (RentalOrder $o): int => (int) $o->id, $orders->items())),
            'counts' => $counts,
            'unpaidCount' => $unpaidCount,
            'dueCount' => RentalOrder::query()->where('state', RentalOrder::STATE_ACTIVE)->whereDate('end_date', '<=', now())->count(),
            'totalCount' => (int) $counts->sum(),
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
            'canCancel' => $this->mayAccess(Permission::Write),
            'canDelete' => $this->mayAccess(Permission::Unlink),
            'canPayLink' => $this->mayAccess(Permission::Write) && $this->portalEnabled(),
        ]);
    }
}

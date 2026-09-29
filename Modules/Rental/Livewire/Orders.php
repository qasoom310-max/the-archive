<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

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
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalOrder;
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

    public function render(): View
    {
        $query = app(RentalOrderRows::class)->query($this->tab, $this->from, $this->to, $this->search);

        $counts = RentalOrder::query()
            ->selectRaw('state, COUNT(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');

        $unpaidCount = $this->applyOwing(RentalOrder::query())->count();

        $user = Auth::user();

        return view('rental::orders', [
            'orders' => $query->paginate(20),
            'counts' => $counts,
            'unpaidCount' => $unpaidCount,
            'totalCount' => (int) $counts->sum(),
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
            'canCancel' => $this->mayAccess(Permission::Write),
            'canDelete' => $this->mayAccess(Permission::Unlink),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Storefront\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Services\StorefrontOrderAcceptor;
use RuntimeException;

/**
 * The website orders inbox. A member of staff checks each new order (calls or
 * WhatsApps the customer if needed) and either accepts it — it becomes a
 * pay-on-delivery remote order in the open register and moves to the Remote
 * sales queue — or rejects it with a reason.
 */
#[Layout('components.layouts.app')]
#[Title('Website orders')]
final class StorefrontOrders extends Component
{
    use WithPagination;

    /** new | accepted | rejected | all */
    public string $filter = 'new';

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public string $message = '';

    public string $error = '';

    public function mount(): void
    {
        $this->guard(Permission::Read);
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', $permission);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['new', 'accepted', 'rejected', 'all'], true) ? $filter : 'new';
        $this->resetPage();
    }

    public function accept(int $id, StorefrontOrderAcceptor $acceptor): void
    {
        $this->guard(Permission::Write);
        $this->reset('message', 'error');

        $order = StorefrontOrder::query()->find($id);
        if ($order === null) {
            return;
        }

        try {
            $pos = $acceptor->accept($order, $this->userId());
            $this->message = __('Accepted as :ref — it is now in Remote sales as New.', ['ref' => $pos->reference]);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function startReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
    }

    public function confirmReject(StorefrontOrderAcceptor $acceptor): void
    {
        $this->guard(Permission::Write);
        $this->reset('message', 'error');

        $order = $this->rejectingId !== null ? StorefrontOrder::query()->find($this->rejectingId) : null;
        if ($order === null) {
            return;
        }

        try {
            $acceptor->reject($order, $this->userId(), $this->rejectReason);
            $this->message = __('Rejected. Nothing was taken from stock.');
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
        $this->rejectingId = null;
    }

    private function userId(): ?int
    {
        $id = Auth::id();

        return is_numeric($id) ? (int) $id : null;
    }

    public function render(): View
    {
        $counts = StorefrontOrder::query()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(static fn ($n): int => (int) $n)
            ->all();

        $orders = StorefrontOrder::query()
            ->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))
            ->orderByDesc('id')
            ->paginate(25);

        return view('storefront::orders', [
            'orders' => $orders,
            'counts' => $counts,
        ]);
    }
}

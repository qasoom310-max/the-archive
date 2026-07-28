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
use Modules\Pos\Models\PosSettlement;
use Modules\Pos\Services\PosSettlementService;

/**
 * Delivery money tracker: what the delivery company still holds, what we've
 * asked for, and what actually landed.
 *
 * A delivery sale's cash is collected by someone else, so "paid" does not mean
 * "in our account". This screen closes that gap — request a payout, then record
 * what arrived and see immediately whether it matches what was owed.
 */
#[Layout('components.layouts.app')]
#[Title('Delivery money')]
final class PosSettlements extends Component
{
    /** Settlement whose "record received" form is open (null = none). */
    public ?int $receivingId = null;

    public string $receivedAmount = '';

    public string $receivedMethod = 'bank_transfer';

    public string $receivedOn = '';

    public string $receiptNote = '';

    public function mount(): void
    {
        // Delivery money only exists when remote sales are on. A disabled
        // feature is not a broken page — send them to the POS home.
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

    /** Ask the delivery company for everything currently collected. */
    public function requestPayout(): void
    {
        $this->guard(Permission::Write);

        $settlement = app(PosSettlementService::class)->requestPayout();

        session()->flash('settlement_status', $settlement !== null
            ? __('Payout :ref requested.', ['ref' => $settlement->reference])
            : __('Nothing is awaiting settlement.'));
    }

    /** Open the "money arrived" form for a requested payout. */
    public function openReceive(int $settlementId): void
    {
        $this->guard(Permission::Write);

        $settlement = PosSettlement::query()->find($settlementId);
        if ($settlement === null || $settlement->isReceived()) {
            return;
        }

        $this->receivingId = $settlementId;
        // Prefilled with what we expect — the common case is an exact match, and
        // the cashier only edits it when the transfer was wrong.
        $this->receivedAmount = number_format((float) $settlement->expected_amount, 3, '.', '');
        $this->receivedMethod = 'bank_transfer';
        $this->receivedOn = now()->toDateString();
        $this->receiptNote = '';
        $this->resetErrorBag();
    }

    public function cancelReceive(): void
    {
        $this->receivingId = null;
        $this->receivedAmount = '';
        $this->receiptNote = '';
    }

    /** Record the money that actually landed and reconcile it. */
    public function confirmReceive(): void
    {
        $this->guard(Permission::Write);

        if ($this->receivingId === null) {
            return;
        }

        $this->validate([
            'receivedAmount' => ['required', 'numeric', 'min:0'],
            'receivedOn' => ['nullable', 'date'],
            'receiptNote' => ['nullable', 'string', 'max:500'],
        ]);

        $settlement = PosSettlement::query()->find($this->receivingId);
        if ($settlement === null) {
            return;
        }

        $settlement = app(PosSettlementService::class)->recordReceipt(
            $settlement,
            (float) $this->receivedAmount,
            $this->receivedMethod,
            $this->receivedOn,
            $this->receiptNote,
        );

        $this->cancelReceive();

        session()->flash('settlement_status', $settlement->hasDiscrepancy()
            ? __('Recorded — but the amount does NOT match what was requested. Check it.')
            : __('Received and matched. Money is in your account.'));
    }

    /** Undo a payout request that was never actually sent. */
    public function cancelRequest(int $settlementId): void
    {
        $this->guard(Permission::Write);

        $settlement = PosSettlement::query()->find($settlementId);
        if ($settlement === null) {
            return;
        }

        app(PosSettlementService::class)->cancel($settlement);
        session()->flash('settlement_status', __('Payout request cancelled — its orders are awaiting settlement again.'));
    }

    public function render(): View
    {
        $service = app(PosSettlementService::class);

        $settlements = PosSettlement::query()
            ->withCount('orders')
            ->latest('id')
            ->limit(60)
            ->get();

        return view('pos::settlements', [
            'pending' => $service->pendingSummary(),
            'settlements' => $settlements,
            // Money asked for but not yet in the account.
            'awaitingTransfer' => round((float) $settlements
                ->reject(fn (PosSettlement $s): bool => $s->isReceived())
                ->sum('expected_amount'), 3),
            'canManage' => app(AccessControl::class)->allows(Auth::user(), 'pos.order', Permission::Write),
        ]);
    }
}

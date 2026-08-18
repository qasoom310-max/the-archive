<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Models\PosPayment;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosSessionManager;

#[Layout('components.layouts.app')]
#[Title('POS Session')]
final class PosSessionPage extends Component
{
    public int $sessionId;

    public string $countedCash = '';

    public function mount(int $id): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.session', Permission::Read);

        // Shared register: any authorised user may view/manage it — no
        // per-user ownership gate (closing is manager-only, see below).
        $this->sessionId = PosSession::query()->findOrFail($id)->id;
    }

    private function isManager(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }

    /**
     * Who may count the drawer and close the shared register: a manager always,
     * or any cashier when the business turns on "Cashiers can close the
     * register" (POS → Settings). Off by default, so it stays manager-only.
     */
    private function canClose(): bool
    {
        return $this->isManager() || Features::enabled(Feature::CashierClose);
    }

    public function closeSession(): void
    {
        abort_unless($this->canClose(), 403, 'You are not allowed to close the register.');

        $session = PosSession::query()->findOrFail($this->sessionId);

        if ($session->isOpen()) {
            $session->close(round((float) $this->countedCash, 2));
        }
    }

    public function render(): View
    {
        $session = PosSession::query()->with('user')->findOrFail($this->sessionId);

        $orders = $session->orders()
            ->with('user')
            ->whereIn('state', [OrderState::Paid->value, OrderState::Done->value])
            ->get();

        /** @var array<string, float> $byMethod */
        $byMethod = [];
        $payments = PosPayment::query()
            ->with('method')
            ->whereHas('order', fn ($q) => $q
                ->where('pos_session_id', $session->id)
                ->whereIn('state', [OrderState::Paid->value, OrderState::Done->value]))
            ->get();

        foreach ($payments as $payment) {
            $name = $payment->method->name;
            $byMethod[$name] = round(($byMethod[$name] ?? 0.0) + $payment->amount, 2);
        }

        // Purchases tagged to this session (when the Purchases module is
        // installed). Queried via the table to keep POS decoupled from it.
        $purchases = collect();
        if (Schema::hasTable('purchases')) {
            $purchases = DB::table('purchases')
                ->where('pos_session_id', $session->id)
                ->orderByDesc('id')
                ->get(['id', 'reference', 'name', 'date', 'total', 'state']);
        }

        return view('pos::session', [
            'session' => $session,
            'orders' => $orders,
            'ordersCount' => $orders->count(),
            'salesTotal' => $session->salesTotal(),
            'expectedCash' => $session->expectedCash(),
            'byMethod' => $byMethod,
            'isManager' => $this->isManager(),
            'canClose' => $this->canClose(),
            'participants' => app(PosSessionManager::class)->activeParticipants($session),
            'purchases' => $purchases,
            'purchasesTotal' => round((float) $purchases->sum('total'), 2),
        ]);
    }
}

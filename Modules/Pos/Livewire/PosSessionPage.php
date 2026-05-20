<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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

    public function closeSession(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.session', Permission::Write);
        abort_unless($this->isManager(), 403, 'Only a manager can close the register.');

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

        return view('pos::session', [
            'session' => $session,
            'orders' => $orders,
            'ordersCount' => $orders->count(),
            'salesTotal' => $session->salesTotal(),
            'expectedCash' => $session->expectedCash(),
            'byMethod' => $byMethod,
            'isManager' => $this->isManager(),
            'participants' => app(PosSessionManager::class)->activeParticipants($session),
        ]);
    }
}

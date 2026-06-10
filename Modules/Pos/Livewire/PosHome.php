<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Navigation\ModuleMenu;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosSessionManager;

/**
 * Entry screen for the single global register. There is at most one open
 * session for the whole store: if it exists, every authorised user
 * "Resumes selling" into it; otherwise a manager-style "Open" creates
 * the singleton. No per-user sessions.
 */
#[Layout('components.layouts.app')]
#[Title('Point of Sale')]
final class PosHome extends Component
{
    public string $openingCash = '0.00';

    public function openSession(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.session', Permission::Create);

        $userId = Auth::id();
        $userId = is_int($userId) ? $userId : null;

        // Get-or-create the singleton: if the register is already open
        // this just joins it (no duplicate session).
        $session = app(PosSessionManager::class)->openOrResume(
            round((float) $this->openingCash, 2),
            $userId,
        );

        $this->redirect(url('/app/pos/session/' . $session->id . '/terminal'), navigate: true);
    }

    public function render(): View
    {
        $user = Auth::user();
        $manager = app(PosSessionManager::class);
        $active = $manager->getActiveSession();

        // Odoo-style app-home tiles for every POS model the user may Read
        // (same source as the contextual sidebar — see ModuleMenu).
        $module = IrModule::query()->where('name', 'pos')->first();
        $tiles = $module !== null ? app(ModuleMenu::class)->items($module, $user) : [];

        return view('pos::home', [
            'active' => $active,
            'participants' => $active !== null ? $manager->activeParticipants($active) : null,
            'recent' => PosSession::query()->with('user')
                ->where('state', SessionState::Closed)
                ->latest('closed_at')->limit(10)->get(),
            'isManager' => $user instanceof User && $user->isAdmin(),
            'tiles' => $tiles,
        ]);
    }
}

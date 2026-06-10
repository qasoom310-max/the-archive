<?php

declare(strict_types=1);

namespace App\Livewire\Navigation;

use App\Erp\Navigation\ModuleMenu;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Contextual left sidebar. Its contents change with the active module
 * (resolved from the `/app/{module}` URL segment); each registered
 * ir_model of that module becomes a menu entry (see {@see ModuleMenu}).
 */
final class Sidebar extends Component
{
    public ?string $activeModule = null;

    public function mount(?string $activeModule = null): void
    {
        $this->activeModule = $activeModule;
    }

    public function render(): View
    {
        $module = $this->activeModule !== null
            ? IrModule::query()->where('name', $this->activeModule)->first()
            : null;

        $user = Auth::user();

        $entries = $module !== null
            ? app(ModuleMenu::class)->items($module, $user)
            : [];

        return view('livewire.navigation.sidebar', [
            'module' => $module,
            'entries' => $entries,
            'isAdmin' => $user instanceof User && $user->isAdmin(),
        ]);
    }
}

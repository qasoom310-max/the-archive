<?php

declare(strict_types=1);

namespace App\Livewire\Navigation;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Contextual left sidebar. Its contents change with the active module
 * (resolved from the `/app/{module}` URL segment); each registered
 * ir_model of that module becomes a menu entry.
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

        $access = app(AccessControl::class);
        $user = Auth::user();

        $entries = $module !== null
            ? IrModel::query()
                ->where('module', $module->name)
                ->orderBy('name')
                ->get()
                ->filter(fn (IrModel $m): bool => $access->allows($user, $m->model, Permission::Read))
                ->map(function (IrModel $m) use ($module): array {
                    $slug = $this->resourceSlug($module->name, $m->model);

                    // The module's primary model — its resource name equals
                    // the module name (e.g. project.project under "project") —
                    // links to the module home (/app/{module}) instead of a
                    // redundant /app/project/project.
                    return [
                        'label' => $m->name,
                        'url' => $slug === $module->name
                            ? url('/app/' . $module->name)
                            : url('/app/' . $module->name . '/' . $slug),
                    ];
                })
                ->values()
                ->all()
            : [];

        return view('livewire.navigation.sidebar', [
            'module' => $module,
            'entries' => $entries,
            'isAdmin' => $user instanceof User && $user->isAdmin(),
        ]);
    }

    /**
     * Map a dotted model id to a clean URL slug, dropping the redundant
     * module prefix (e.g. "contacts.partner" under "contacts" → "partner").
     */
    private function resourceSlug(string $module, string $model): string
    {
        $resource = Str::startsWith($model, $module . '.')
            ? Str::after($model, $module . '.')
            : Str::afterLast($model, '.');

        return str_replace('.', '/', $resource);
    }
}


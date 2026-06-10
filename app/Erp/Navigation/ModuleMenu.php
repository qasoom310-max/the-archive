<?php

declare(strict_types=1);

namespace App\Erp\Navigation;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Builds a module's menu: every registered ir_model the user may Read,
 * mapped to its resource URL. Shared by the contextual {@see \App\Livewire\Navigation\Sidebar}
 * and the Odoo-style app-home tile dashboards so both stay in lock-step
 * (same entries, same ACL filtering, same slug rules).
 */
final class ModuleMenu
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return list<array{label: string, model: string, slug: string, url: string}>
     */
    public function items(IrModule $module, ?Authenticatable $user): array
    {
        return IrModel::query()
            ->where('module', $module->name)
            ->orderBy('name')
            ->get()
            ->filter(fn (IrModel $m): bool => $this->access->allows($user, $m->model, Permission::Read))
            ->map(function (IrModel $m) use ($module): array {
                $slug = $this->resourceSlug($module->name, $m->model);

                // The module's primary model — its resource name equals the
                // module name (e.g. project.project under "project") — links
                // to the module home instead of a redundant /app/project/project.
                return [
                    'label' => $m->name,
                    'model' => $m->model,
                    'slug' => $slug,
                    'url' => $slug === $module->name
                        ? url('/app/' . $module->name)
                        : url('/app/' . $module->name . '/' . $slug),
                ];
            })
            ->values()
            ->all();
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

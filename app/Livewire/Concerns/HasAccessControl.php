<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Support\Facades\Auth;

/**
 * Shared permission check for the engine view components (form / list / kanban).
 * `may()` short-circuits to allow when the component has no bound model key.
 *
 * Requires the using component to expose a `string $modelKey` property.
 */
trait HasAccessControl
{
    private function access(): AccessControl
    {
        return app(AccessControl::class);
    }

    private function may(Permission $permission): bool
    {
        return $this->modelKey === ''
            || $this->access()->allows(Auth::user(), $this->modelKey, $permission);
    }
}

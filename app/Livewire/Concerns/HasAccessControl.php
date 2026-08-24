<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Support\Facades\Auth;

/**
 * Shared permission check for the engine view components (form / list / kanban).
 *
 * `may()` DENIES when the component has no bound model key. It used to allow —
 * which made an unbound key a wildcard: `$modelKey` is a public Livewire
 * property, so a browser could blank it and pass every permission check on the
 * component. Every call site passes an explicit `model-key`, so an empty key
 * only ever means "tampered with" or "misconfigured", and both must fail closed.
 * The properties are additionally `#[Locked]` on each component.
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
        return $this->modelKey !== ''
            && $this->access()->allows(Auth::user(), $this->modelKey, $permission);
    }
}

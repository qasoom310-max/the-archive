<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Support\Facades\Auth;

/**
 * Permission checks for a BESPOKE module screen — one that manages records
 * itself instead of embedding the engine's list/form components (which carry
 * their own gate via {@see HasAccessControl}).
 *
 * Such a screen is otherwise protected by nothing but `auth`, so any account
 * with a login could operate it regardless of the per-app access an admin
 * granted in Settings → Users. The Rental and Limousine screens shipped that
 * way: a cashier could create and price invoices, bookings and receipts.
 *
 * Use it like this:
 *
 *     use GuardsModelAccess;
 *
 *     protected function accessModelKey(): string { return 'rental.invoice'; }
 *
 *     public function mount(): void { $this->guardAccess(Permission::Read); }
 *     public function save(): void { $this->guardAccess(Permission::Write); }
 *
 * EVERY mutating action must re-check. `mount()` runs once and Livewire then
 * dispatches straight to methods, so a mount-only gate leaves every button on
 * the page open (the same hole that was found on Production and the Kitchen
 * Display).
 */
trait GuardsModelAccess
{
    /** The `ir_model` key this screen operates on, e.g. `rental.invoice`. */
    abstract protected function accessModelKey(): string;

    /** Throws AuthorizationException (→ 403) when the user may not do this. */
    protected function guardAccess(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), $this->accessModelKey(), $permission);
    }

    /** Non-throwing check, for hiding a control the user may not use. */
    protected function mayAccess(Permission $permission): bool
    {
        return app(AccessControl::class)->allows(Auth::user(), $this->accessModelKey(), $permission);
    }

    /**
     * Guard a save: Create for a brand-new record, Write for an existing one —
     * so a user granted "view + edit" cannot also add records, and vice versa.
     */
    protected function guardSave(bool $isNew): void
    {
        $this->guardAccess($isNew ? Permission::Create : Permission::Write);
    }
}

<?php

declare(strict_types=1);

namespace App\Erp\Admin;

/**
 * The role a user account is given in the Settings → Users form. ONE role per
 * account (mutually exclusive) — this enum is the single source of truth for
 * what each one means, so the `users` flags and the granted `ir_model_access`
 * permissions can never drift from the label the admin picked.
 *
 * Two independent axes:
 *
 *  - **Account flags** (`is_admin` / `is_super_admin` / `is_accountant`) —
 *    system-wide powers, checked directly by `User::isAdmin()` etc.
 *  - **App permissions** ({@see permissions()}) — the `ir_model_access` rules
 *    written per granted app by {@see UserProvisioner::grantApps()}. Only the
 *    non-admin roles use these; an admin bypasses the ACL entirely.
 */
enum StaffRole: string
{
    /** Read-only on the granted apps. */
    case Staff = 'staff';

    /** Staff + may add and edit records (but never delete). */
    case Supervisor = 'supervisor';

    /** Supervisor + may confirm payments were received (and write a receipt by hand). */
    case Accountant = 'accountant';

    /** Full access to everything in this database (bypasses the ACL). */
    case Admin = 'admin';

    /** The owner tier: admin + owner-only powers. A strict superset of Admin. */
    case SuperAdmin = 'super';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Staff, self::Supervisor, self::Accountant, self::Admin, self::SuperAdmin];
    }

    /**
     * Roles only a SUPER admin may assign — the owner tier itself, and the
     * accountant (confirming money was received is a sensitive financial power,
     * deliberately not in a regular admin's gift).
     */
    public function needsSuperAdminToAssign(): bool
    {
        return $this === self::SuperAdmin || $this === self::Accountant;
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin || $this === self::SuperAdmin;
    }

    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }

    public function isAccountant(): bool
    {
        return $this === self::Accountant;
    }

    /**
     * Does this role pick apps? Non-admins are GRANTED access to the ticked
     * apps (an ir_model_access row each); an Administrator is instead NARROWED
     * to them (see {@see isScopableAdmin()}) — either way, this is the "show
     * the app checklist" question. A super admin bypasses everything
     * unconditionally, so a selection is meaningless for that one role only.
     */
    public function grantsApps(): bool
    {
        return ! $this->isAdmin();
    }

    /**
     * An Administrator (never a super admin) can be narrowed to specific
     * apps instead of every app — {@see \App\Erp\Security\AccessControl}
     * still bypasses the ACL within that scope, so this is full access
     * (add/edit/delete) to fewer apps, not reduced access to every app.
     */
    public function isScopableAdmin(): bool
    {
        return $this === self::Admin;
    }

    /** Show the app checklist for this role at all (grant OR scope). */
    public function usesAppPicker(): bool
    {
        return $this->grantsApps() || $this->isScopableAdmin();
    }

    /**
     * The `ir_model_access` permissions granted on each of the user's apps.
     * Delete is never granted from this screen — removing records is an admin
     * action.
     *
     * @return array{read: bool, write: bool, create: bool, unlink: bool}
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Supervisor, self::Accountant => ['read' => true, 'write' => true, 'create' => true, 'unlink' => false],
            default => ['read' => true, 'write' => false, 'create' => false, 'unlink' => false],
        };
    }

    /** Translate at the call site — the enum stays free of framework calls. */
    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff (view only)',
            self::Supervisor => 'Supervisor',
            self::Accountant => 'Accountant',
            self::Admin => 'Administrator',
            self::SuperAdmin => 'Super admin',
        };
    }

    /** One-line explanation shown under the role in the picker. */
    public function description(): string
    {
        return match ($this) {
            self::Staff => 'Can view records in the chosen apps.',
            self::Supervisor => 'Can view, add and edit records in the chosen apps (but not delete them).',
            self::Accountant => 'Can view, add and edit records in the chosen apps (but not delete them), and confirm that payments were received.',
            self::Admin => 'Full access (including delete) to every app and setting in this database — or just the apps you tick below.',
            self::SuperAdmin => 'Everything an administrator can do, plus the owner-only controls.',
        };
    }

    /** Tailwind tone for the badge in the user list. */
    public function color(): string
    {
        return match ($this) {
            self::Staff => 'chrome',
            self::Supervisor => 'sky',
            self::Accountant => 'emerald',
            self::Admin => 'primary',
            self::SuperAdmin => 'amber',
        };
    }

    /** The role an existing account currently holds, derived from its flags. */
    public static function of(\App\Models\User $user): self
    {
        if ($user->isSuperAdmin()) {
            return self::SuperAdmin;
        }

        if ($user->isAdmin()) {
            return self::Admin;
        }

        if ($user->isAccountant()) {
            return self::Accountant;
        }

        // Supervisor vs Staff is a property of the granted ACL rules, not a
        // column — the caller (UserManager) resolves it from the user's
        // per-user group when loading the edit form.
        return self::Staff;
    }
}

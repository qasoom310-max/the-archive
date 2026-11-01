<?php

declare(strict_types=1);

namespace App\Erp\Admin;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's two limousine switches on a staff account (Settings → Users):
 * - {@see LOCK}: a trip's details stay hidden until it has a car and a driver
 *   ({@see User::seesTripDetailsOnlyWhenCrewed()}) — never on an owner;
 * - {@see REMINDERS}: staff-app reminders about trips inside the 12 hours still
 *   without both ({@see User::getsTripReminders()}).
 *
 * Written on every copy of the account — Main and each business database,
 * matched by email — so a switch flipped from any database's Users screen
 * holds wherever the person works: inside a business, the account in use is
 * that database's copy.
 */
final class TripStaffSwitches
{
    public const LOCK = 'trip_details_locked';

    public const REMINDERS = 'trip_reminders';

    public function __construct(private readonly WorkspaceManager $workspaces)
    {
    }

    /** Whether this database's accounts can carry the switches yet (migrated). */
    public function available(): bool
    {
        return Schema::hasColumn('users', self::LOCK) && Schema::hasColumn('users', self::REMINDERS);
    }

    /**
     * @param  self::LOCK|self::REMINDERS  $switch
     */
    public function set(User $user, string $switch, bool $on): void
    {
        $email = $user->email;
        $id = $user->getKey();

        // No email to find the other copies by (or no databases besides this
        // one): this account alone.
        if ($email === null || $email === '' || ! Schema::connection(Workspace::$landlordConnection)->hasTable('workspaces')) {
            $this->write($switch, $on, static fn (Builder $q): Builder => $q->where('id', $id));

            return;
        }

        $byEmail = static fn (Builder $q): Builder => $q->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]);

        foreach ($this->workspaces->all() as $workspace) {
            if ($workspace->is_main) {
                $this->workspaces->withMain(fn () => $this->write($switch, $on, $byEmail));

                continue;
            }

            $path = $workspace->databasePath();
            if ($path !== null && is_file($path)) {
                $this->workspaces->withTenant($path, fn () => $this->write($switch, $on, $byEmail));
            }
        }
    }

    /**
     * @param  Closure(Builder): Builder  $which
     */
    private function write(string $switch, bool $on, Closure $which): int
    {
        if (! Schema::hasColumn('users', $switch)) {
            return 0;
        }

        $query = $which(DB::table('users'));
        if ($switch === self::LOCK && $on) {
            // An owner always sees everything.
            $query->where(static fn (Builder $q): Builder => $q->where('is_super_admin', false)->orWhereNull('is_super_admin'));
        }

        return $query->update([$switch => $on]);
    }
}

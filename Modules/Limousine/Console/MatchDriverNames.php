<?php

declare(strict_types=1);

namespace Modules\Limousine\Console;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoDriverAlias;
use Modules\Limousine\Support\DriverAliases;
use Throwable;

/**
 * Work out by itself who the old system's driver logins were.
 *
 * Trips carried over name a LOGIN — "kown", "smakhlooq", "admin" — and somebody
 * has to say which of those is a driver, which is the office, and which is not
 * a person at all. Asking the owner to answer a hundred and twelve of those by
 * hand is asking him to do the system's job, so the system does what it can
 * from what it already knows and leaves only the genuinely unknowable.
 *
 * Three rules, in order, and only ever on a name nobody has decided yet:
 *
 *  1. **It is a driver** when exactly ONE person in the driver register could
 *     have produced that login — by full name, by first name, by surname, or by
 *     an initial welded to a surname, which is how these logins were built. Two
 *     drivers called Ali make "ali" mean nothing, so nothing is decided.
 *  2. **It is the office** when the login belongs to a USER of this system and
 *     no driver answers to it. Someone who signs in here and is not in the
 *     driver register was booking the trip, not driving it.
 *  3. **It is not a person** when it is one of the logins the old system used
 *     for itself — the shared office machine, the API account, the catch-all
 *     "admin" that a trip was parked on when nobody picked anybody.
 *
 * A decision made here is worth no more than one typed on the screen: the trips
 * are never rewritten, so anything this gets wrong is put right by changing it
 * at /app/limousine/driver-names. That is what makes deciding automatically
 * safe — being wrong costs a click, not a history.
 *
 * Idempotent. Re-run after adding drivers to the register and the names that
 * now have exactly one answer get it.
 */
final class MatchDriverNames extends Command
{
    protected $signature = 'limo:match-driver-names {--workspace= : Only this workspace id} {--pretend : Say what would happen and write nothing}';

    protected $description = "Decide who the old system's driver logins were, as far as the records allow.";

    /**
     * Logins the old system used for itself rather than for a person.
     *
     * Read off the Wanaan export: "admin" carried 1,448 trips and fell from 869
     * in 2023 to 21 in 2026 as the habit was cleaned up; "mac" is the shared
     * office machine (71 trips, but 1,233 bookings entered from it); "via" and
     * "apiuser" are the booking feed; "asprinter" is a Mercedes Sprinter typed
     * into the driver box; "fone rent" is a supplier; "p" is a slip.
     *
     * @var list<string>
     */
    private const SYSTEM_LOGINS = [
        'admin', 'via', 'apiuser', 'mac', 'asprinter', 'fone rent', 'p', 'geasy',
    ];

    public function handle(WorkspaceManager $workspaces): int
    {
        $only = $this->option('workspace');

        foreach ($workspaces->all() as $workspace) {
            if ($only !== null && (string) $workspace->id !== (string) $only) {
                continue;
            }

            $this->line('');
            $this->info($workspace->name . ':');

            try {
                if ($workspace->is_main) {
                    $this->matchHere();

                    continue;
                }

                $path = $workspace->databasePath();

                if ($path === null || ! is_file($path)) {
                    $this->warn('  skipped: its database file is missing.');

                    continue;
                }

                $workspaces->withTenant($path, function (): void {
                    $this->matchHere();
                });
            } catch (Throwable $e) {
                // One workspace failing must never stop the rest, or a deploy.
                $this->warn('  skipped: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * Decide every undecided login in the database this is pointed at.
     */
    private function matchHere(): void
    {
        if (! Schema::hasTable('limo_driver_aliases') || ! Schema::hasTable('limo_legs')) {
            $this->line('  Limousine is not installed here.');

            return;
        }

        $service = app(DriverAliases::class);
        $service->flush();

        $rows = $service->candidates();

        if ($rows === []) {
            $this->line('  No trip names a driver in text.');

            return;
        }

        $userLogins = $this->userLogins();
        $pretend = (bool) $this->option('pretend');

        $matched = [];
        $office = [];
        $left = [];

        foreach ($rows as $row) {
            if ($row['decided'] === true) {
                continue; // somebody has already answered this one
            }

            $key = (string) $row['key'];
            $driverId = $row['suggestion'];

            if ($driverId !== null) {
                $matched[$key] = (int) $driverId;

                continue;
            }

            if (in_array($key, self::SYSTEM_LOGINS, true) || isset($userLogins[$key])) {
                $office[] = $key;

                continue;
            }

            $left[] = $row;
        }

        foreach ($matched as $alias => $driverId) {
            if (! $pretend) {
                LimoDriverAlias::query()->updateOrCreate(
                    ['alias' => $alias],
                    ['driver_id' => $driverId, 'is_office' => false, 'decided_by' => 'Matched automatically'],
                );
            }

            $this->line('  ' . $alias . '  →  ' . ($this->driverName($driverId) ?? '#' . $driverId));
        }

        foreach ($office as $alias) {
            if (! $pretend) {
                LimoDriverAlias::query()->updateOrCreate(
                    ['alias' => $alias],
                    ['driver_id' => null, 'is_office' => true, 'decided_by' => 'Matched automatically'],
                );
            }

            $this->line('  ' . $alias . '  →  office / not a driver');
        }

        $this->line(sprintf(
            '  %d matched to a driver, %d marked as the office, %d left for someone to say.',
            count($matched),
            count($office),
            count($left),
        ));

        // Named, not just counted: these are what the screen is now FOR, and a
        // list of them is the difference between a job and a number.
        foreach (array_slice($left, 0, 15) as $row) {
            $this->line(sprintf('    still unknown: %-16s %d trips', (string) $row['display'], (int) $row['trips']));
        }

        app(DriverAliases::class)->flush();
    }

    /**
     * Every way a person who SIGNS IN here might have been written as a login:
     * their name, and the part of their e-mail before the @.
     *
     * @return array<string, true>
     */
    private function userLogins(): array
    {
        $out = [];

        foreach (User::query()->get(['name', 'email']) as $user) {
            foreach ([(string) ($user->name ?? ''), strtok((string) ($user->email ?? ''), '@') ?: ''] as $form) {
                $key = DriverAliases::key($form);

                if ($key !== '') {
                    $out[$key] = true;
                }
            }
        }

        // Somebody who is BOTH a user here and a driver in the register is a
        // driver: the register is the list of people who drive, and signing in
        // as well does not stop them.
        foreach (LimoDriver::query()->get(['name']) as $driver) {
            unset($out[DriverAliases::key((string) $driver->name)]);
        }

        return $out;
    }

    private function driverName(int $id): ?string
    {
        $driver = LimoDriver::query()->find($id);

        return $driver === null ? null : (string) $driver->name;
    }
}

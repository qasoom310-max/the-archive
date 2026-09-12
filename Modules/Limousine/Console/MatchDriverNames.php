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
 * The rules, in order:
 *
 *  1. **It is a driver** when exactly one person in the register produces that
 *     login from a WHOLE name — the full name, the name welded together, or an
 *     initial stuck to a surname, which is how these logins were built. Only
 *     the person it belongs to produces one of those.
 *  2. **It is the office** when the login belongs to somebody who signs into
 *     this system — by name, or by the part of their e-mail before the `@` —
 *     and NO driver in the register is even half-suggested for it. Someone who
 *     signs in here and answers to no driver was booking the trip, not driving
 *     it. If a driver IS half-suggested, the two could be one person, and that
 *     is a question rather than an answer: it goes to the screen.
 *  3. **It is not a person** when it is one of the logins the old system used
 *     for itself — the shared office machine, the API account, the catch-all
 *     "admin" a trip was parked on when nobody picked anybody.
 *  4. **A half name decides nothing on its own.** A login that matches only a
 *     driver's FIRST name or only a surname is offered on the screen and left
 *     there. Half the office shares a first name with a driver: "mariam" is
 *     both the dispatcher who entered 1,581 bookings and, if the register
 *     holds one, a driver called Mariam. That is a question for a person.
 *
 * The machine may revise ITS OWN answers — the rules improve, and an answer it
 * gave under a worse rule should not outlive it — but a decision a PERSON made
 * on the screen is never touched. `auto` is what tells them apart.
 *
 * Deciding automatically is only safe because nothing is rewritten: the trips
 * keep the text the import gave them, so anything this gets wrong is put right
 * by changing it at /app/limousine/driver-names. Being wrong costs a click,
 * not a history.
 *
 * Idempotent. Re-run after adding drivers to the register and the logins that
 * now have exactly one whole-name answer get it.
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
        $withdrawn = [];

        foreach ($rows as $row) {
            $key = (string) $row['key'];

            // A person's answer stands, whatever the rules now say. Only an
            // answer this command gave itself is its to reconsider.
            if ($row['decided'] === true && $row['auto'] === false) {
                continue;
            }

            $hadAuto = $row['auto'] === true && ($row['driverId'] !== null || $row['office'] === true);

            // 1. A whole name: only the person it belongs to produces it.
            if ($row['suggestion'] !== null && $row['suggestionStrong'] === true) {
                $matched[$key] = (int) $row['suggestion'];

                continue;
            }

            $isOfficeLogin = in_array($key, self::SYSTEM_LOGINS, true) || isset($userLogins[$key]);

            // 2 + 3. Signs in here, or is one of the old system's own logins —
            //        and no driver is even half-suggested for it.
            if ($isOfficeLogin && $row['suggestion'] === null) {
                $office[] = $key;

                continue;
            }

            // 4. Half a name, or nothing at all: a question for a person. If an
            // earlier run answered it under a looser rule, take that back.
            if ($hadAuto) {
                $withdrawn[] = $key;
            }

            $left[] = $row;
        }

        foreach ($matched as $alias => $driverId) {
            if (! $pretend) {
                $this->decide($alias, $driverId, false);
            }

            $this->line('  ' . $alias . '  →  ' . ($this->driverName($driverId) ?? '#' . $driverId));
        }

        foreach ($office as $alias) {
            if (! $pretend) {
                $this->decide($alias, null, true);
            }

            $this->line('  ' . $alias . '  →  office / not a driver');
        }

        foreach ($withdrawn as $alias) {
            if (! $pretend) {
                // Kept as a row rather than removed, so the screen can still
                // show that this one was looked at and found unanswerable.
                $this->decide($alias, null, false);
            }

            $this->warn('  ' . $alias . '  →  taken back: only half a name matched, so a person should say');
        }

        $this->line(sprintf(
            '  %d matched to a driver, %d marked as the office, %d left for someone to say%s.',
            count($matched),
            count($office),
            count($left),
            $withdrawn === [] ? '' : sprintf(' (%d taken back from an earlier run)', count($withdrawn)),
        ));

        // Named, not just counted: these are what the screen is now FOR, and a
        // list of them is the difference between a job and a number.
        foreach (array_slice($left, 0, 15) as $row) {
            $this->line(sprintf('    still unknown: %-16s %d trips', (string) $row['display'], (int) $row['trips']));
        }

        // The other half of the same question. An unanswered login and a driver
        // nobody has claimed are usually the two ends of one missing match, and
        // seeing them apart is what makes the pairing invisible.
        $this->unclaimedDrivers();

        app(DriverAliases::class)->flush();
    }

    /**
     * Drivers in the register that no old login points at.
     *
     * Either they only ever drove for this system, or their login is sitting in
     * the unanswered list under a spelling nothing matched.
     */
    private function unclaimedDrivers(): void
    {
        $claimed = LimoDriverAlias::query()
            ->whereNotNull('driver_id')
            ->pluck('driver_id')
            ->all();

        $names = LimoDriver::query()
            ->when($claimed !== [], fn ($q) => $q->whereNotIn('id', $claimed))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($names === []) {
            return;
        }

        $this->line(sprintf('    %d driver(s) in the register that no old name points at:', count($names)));

        foreach (array_chunk($names, 4) as $chunk) {
            $this->line('      ' . implode(' · ', array_map('strval', $chunk)));
        }
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

    /**
     * Write an answer of the machine's own, stamped so it can be told from a
     * person's and revised later without touching theirs.
     */
    private function decide(string $alias, ?int $driverId, bool $office): void
    {
        LimoDriverAlias::query()->updateOrCreate(
            ['alias' => $alias],
            [
                'driver_id' => $driverId,
                'is_office' => $office,
                'auto' => true,
                'decided_by' => 'Matched automatically',
            ],
        );
    }

    private function driverName(int $id): ?string
    {
        $driver = LimoDriver::query()->find($id);

        return $driver === null ? null : (string) $driver->name;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoDriverAlias;

/**
 * Reading an old system's driver name as a person.
 *
 * Imported trips name their driver in text, and that text is the previous
 * system's LOGIN. So the earnings league ranked logins, a driver's job history
 * could not find his older trips, and petty cash — which points at a driver id
 * — had nothing to attach to. This turns a login back into a driver.
 *
 * The trips are never rewritten. Every screen resolves THROUGH this table, so
 * a mapping decided wrongly is a mapping changed, not a history to repair.
 *
 * @phpstan-type Candidate array{key: string, display: string, trips: int, collected: float,
 *     linked: int, from: string, to: string, driverId: int|null, office: bool, auto: bool,
 *     decided: bool, suggestion: int|null, suggestionStrong: bool, slug: string,
 *     cars: list<string>, closest: list<array{id: int, name: string}>}
 */
final class DriverAliases
{
    /** @var array<string, array{driverId: int|null, office: bool, auto: bool}>|null */
    private ?array $map = null;

    /** @var array<int, string>|null */
    private ?array $driverNames = null;

    /**
     * Forget what was read, so a mapping just saved is the one answered with.
     *
     * Bound as a singleton for the length of a request — a queue of five
     * hundred rows must not ask the database who "kown" is five hundred times —
     * and {@see \Modules\Limousine\Models\LimoDriverAlias} calls this whenever a
     * row is written, so the cache cannot outlive the answer it holds.
     */
    public function flush(): void
    {
        $this->map = null;
        $this->driverNames = null;
    }

    /**
     * A driver name reduced to the form two spellings of it share.
     *
     * Lowercased and space-collapsed, so "Qmohmd Maki" and "qmohmd  maki" are
     * one name. Nothing else is stripped: a dot or a hyphen is part of how the
     * old system spelled it and two names that differ only there are still two.
     */
    public static function key(string $raw): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $raw) ?? ''));
    }

    /**
     * What a name on a trip means: the driver to credit, or the office.
     *
     * An undecided name is returned as itself, exactly as the screens showed it
     * before — an unanswered question must not quietly become an answer.
     *
     * @return array{name: string, driverId: int|null, office: bool}
     */
    public function resolve(string $raw): array
    {
        $key = self::key($raw);

        if ($key === '') {
            return ['name' => '', 'driverId' => null, 'office' => false];
        }

        $entry = $this->map()[$key] ?? null;

        if ($entry === null) {
            return ['name' => trim($raw), 'driverId' => null, 'office' => false];
        }

        if ($entry['office']) {
            return ['name' => trim($raw), 'driverId' => null, 'office' => true];
        }

        $id = $entry['driverId'];

        if ($id === null) {
            return ['name' => trim($raw), 'driverId' => null, 'office' => false];
        }

        // A driver deleted from the register since the mapping was made leaves
        // the name standing rather than blanking the trip.
        return [
            'name' => $this->driverNames()[$id] ?? trim($raw),
            'driverId' => $id,
            'office' => false,
        ];
    }

    /**
     * Every old name that means this driver, as stored keys.
     *
     * Used to find the trips that name him in text alone, so his job history is
     * whole rather than starting on the day the ERP went live.
     *
     * @return list<string>
     */
    public function aliasesFor(int $driverId): array
    {
        $out = [];

        foreach ($this->map() as $key => $entry) {
            if ($entry['driverId'] === $driverId) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Every distinct driver name that appears on a trip, with the work behind
     * it — so the person deciding can see that "kown" is 489 trips and worth
     * getting right, while "p" is 32 and probably a slip.
     *
     * @return list<Candidate>
     */
    public function candidates(): array
    {
        if (! Schema::hasTable('limo_legs') || ! Schema::hasTable('limo_bookings')) {
            return [];
        }

        $rows = DB::table('limo_legs as l')
            ->join('limo_bookings as b', 'b.id', '=', 'l.legable_id')
            ->where('l.legable_type', LimoBooking::class)
            ->whereNotNull('l.driver')
            ->where('l.driver', '!=', '')
            ->groupBy('l.driver')
            ->get([
                'l.driver',
                DB::raw('count(*) as trips'),
                DB::raw("sum(case when b.payment_status = 'paid' then l.net_amount else 0 end) as collected"),
                DB::raw('sum(case when l.driver_id is null then 0 else 1 end) as linked'),
                DB::raw('min(l.start_at) as first_at'),
                DB::raw('max(l.start_at) as last_at'),
            ]);

        /** @var array<string, Candidate> $merged */
        $merged = [];

        foreach ($rows as $row) {
            $raw = (string) $row->driver;
            $key = self::key($raw);

            if ($key === '') {
                continue;
            }

            $entry = $this->map()[$key] ?? null;

            // Two spellings of one name are one row: the office types "Habib"
            // and the import wrote "habib", and they are not two drivers.
            $merged[$key] ??= [
                'key' => $key,
                'display' => trim($raw),
                'trips' => 0,
                'collected' => 0.0,
                'linked' => 0,
                'from' => '',
                'to' => '',
                'driverId' => $entry['driverId'] ?? null,
                'office' => $entry['office'] ?? false,
                'auto' => $entry['auto'] ?? false,
                // A machine row with no answer in it is the machine saying it
                // does not know — which is still an open question. A PERSON'S
                // empty row is an answer: leave this one alone.
                'decided' => $entry !== null
                    && ($entry['driverId'] !== null || $entry['office'] === true || $entry['auto'] === false),
                'suggestion' => null,
                'suggestionStrong' => false,
                'cars' => [],
                'closest' => [],
                'slug' => substr(sha1($key), 0, 12),
            ];

            $merged[$key]['trips'] += (int) $row->trips;
            $merged[$key]['collected'] = round($merged[$key]['collected'] + (float) $row->collected, 3);
            $merged[$key]['linked'] += (int) $row->linked;
            $merged[$key]['from'] = $this->earlier($merged[$key]['from'], (string) ($row->first_at ?? ''));
            $merged[$key]['to'] = $this->later($merged[$key]['to'], (string) ($row->last_at ?? ''));
        }

        $suggestions = $this->suggestions(array_keys($merged));
        $cars = $this->carsByName();
        $closest = $this->closest(array_keys($merged));

        foreach ($merged as $key => $row) {
            $merged[$key]['cars'] = $cars[$key] ?? [];
            $merged[$key]['closest'] = $closest[$key] ?? [];
        }

        foreach ($merged as $key => $row) {
            // Worked out for anything a PERSON has not settled — including a row
            // the matcher answered itself, which it may need to re-check when
            // its rules change. Without this, re-checking sees no match and
            // withdraws an answer that was right.
            if ($row['decided'] === true && $row['auto'] === false) {
                continue;
            }

            $hit = $suggestions[$key] ?? null;

            if ($hit !== null) {
                $merged[$key]['suggestion'] = $hit['driverId'];
                $merged[$key]['suggestionStrong'] = $hit['strong'];
            }
        }

        $out = array_values($merged);

        // Heaviest first: the names carrying the most trips are the ones whose
        // being wrong distorts the most.
        usort($out, static fn (array $a, array $b): int => $b['trips'] <=> $a['trips']);

        return $out;
    }

    /**
     * Record the decisions. `$choices` is slug => '' | 'office' | driver id.
     *
     * An empty choice is stored as an undecided row rather than removed, so a
     * name cleared here does not come back wearing yesterday's suggestion.
     *
     * @param  array<string, string>  $choices
     * @param  list<Candidate>  $candidates
     * @return array{drivers: int, office: int, undecided: int}
     */
    public function save(array $choices, array $candidates, ?string $decidedBy = null): array
    {
        $valid = array_flip(array_map(
            static fn (LimoDriver $d): int => (int) $d->getKey(),
            LimoDriver::query()->get()->all(),
        ));

        $counts = ['drivers' => 0, 'office' => 0, 'undecided' => 0];

        foreach ($candidates as $row) {
            if (! array_key_exists($row['slug'], $choices)) {
                continue;
            }

            $choice = trim($choices[$row['slug']]);

            $driverId = null;
            $office = false;

            if ($choice === 'office') {
                $office = true;
                $counts['office']++;
            } elseif ($choice !== '' && ctype_digit($choice) && isset($valid[(int) $choice])) {
                $driverId = (int) $choice;
                $counts['drivers']++;
            } else {
                $counts['undecided']++;
            }

            LimoDriverAlias::query()->updateOrCreate(
                ['alias' => $row['key']],
                ['driver_id' => $driverId, 'is_office' => $office, 'auto' => false, 'decided_by' => $decidedBy],
            );
        }

        $this->flush();

        return $counts;
    }

    /**
     * The cars a name was driving, most used first.
     *
     * This is the fact that identifies a person when a name cannot. Somebody in
     * the office looks at "37398 FORD EXPEDITION, every week for two years" and
     * knows exactly who that was — and no amount of matching on spelling gets
     * there. Three is enough to recognise a round; a full list is a wall.
     *
     * @return array<string, list<string>>
     */
    private function carsByName(): array
    {
        if (! Schema::hasTable('limo_legs')) {
            return [];
        }

        $rows = DB::table('limo_legs')
            ->where('legable_type', LimoBooking::class)
            ->whereNotNull('driver')->where('driver', '!=', '')
            ->whereNotNull('vehicle')->where('vehicle', '!=', '')
            ->groupBy('driver', 'vehicle')
            ->get(['driver', 'vehicle', DB::raw('count(*) as trips')]);

        /** @var array<string, array<string, int>> $tally */
        $tally = [];

        foreach ($rows as $row) {
            $key = self::key((string) $row->driver);

            if ($key === '') {
                continue;
            }

            $car = trim((string) $row->vehicle);
            $tally[$key][$car] = ($tally[$key][$car] ?? 0) + (int) $row->trips;
        }

        $out = [];

        foreach ($tally as $key => $cars) {
            arsort($cars);
            $out[$key] = array_slice(array_keys($cars), 0, 3);
        }

        return $out;
    }

    /**
     * The drivers whose names come nearest this login, best first.
     *
     * Offered for the eye, never acted on: "smakhlooq" against a register that
     * spells it "Makhloog" is a near miss a person settles at a glance and a
     * matcher should not settle at all. Shown even when a login is ambiguous —
     * ESPECIALLY then, because ambiguous means several plausible people and
     * naming them is the whole help.
     *
     * @param  list<string>  $keys
     * @return array<string, list<array{id: int, name: string}>>
     */
    private function closest(array $keys): array
    {
        /** @var list<array{id: int, name: string, forms: list<string>}> $drivers */
        $drivers = [];

        foreach (LimoDriver::query()->get(['id', 'name']) as $driver) {
            $name = self::key((string) $driver->name);

            if ($name === '') {
                continue;
            }

            $parts = array_values(array_filter(explode(' ', $name), static fn (string $p): bool => $p !== ''));

            if ($parts === []) {
                continue;
            }

            $first = $parts[0];
            $last = $parts[count($parts) - 1];

            $drivers[] = [
                'id' => (int) $driver->getKey(),
                'name' => (string) $driver->name,
                'forms' => array_values(array_unique([
                    $name,
                    str_replace(' ', '', $name),
                    $first,
                    $last,
                    mb_substr($first, 0, 1) . $last,
                    $first . mb_substr($last, 0, 1),
                ])),
            ];
        }

        $out = [];

        foreach ($keys as $key) {
            $probe = str_replace(' ', '', $key);
            $scored = [];

            foreach ($drivers as $driver) {
                $best = 0.0;

                foreach ($driver['forms'] as $form) {
                    $percent = 0.0;
                    similar_text($probe, $form, $percent);
                    $best = max($best, $percent);
                }

                if ($best >= 60.0) {
                    $scored[] = ['id' => $driver['id'], 'name' => $driver['name'], 'score' => $best];
                }
            }

            usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            $out[$key] = array_map(
                static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name']],
                array_slice($scored, 0, 3),
            );
        }

        return $out;
    }

    /**
     * A first guess at who a login belongs to.
     *
     * The old system's logins are built the way office logins usually are:
     * a first name ("habib"), a surname, or an initial welded to a surname
     * ("smakhlooq", "hbusafwan"). Each register driver is reduced to those same
     * forms, and a guess is offered only when exactly ONE driver produces the
     * login — two Alis make "ali" mean nothing, so nothing is suggested.
     *
     * A suggestion is never saved on its own. It arrives in the box for the
     * person to agree with, and the screen says plainly that it is a guess.
     *
     * @param  list<string>  $keys
     * @return array<string, array{driverId: int, strong: bool}>
     */
    private function suggestions(array $keys): array
    {
        /** @var array<string, array<int, int>> $strong */
        $strong = [];
        /** @var array<string, array<int, int>> $weak */
        $weak = [];

        foreach (LimoDriver::query()->get(['id', 'name']) as $driver) {
            $id = (int) $driver->getKey();
            $name = self::key((string) $driver->name);

            if ($name === '') {
                continue;
            }

            $parts = array_values(array_filter(explode(' ', $name), static fn (string $p): bool => $p !== ''));

            if ($parts === []) {
                continue;
            }

            $first = $parts[0];
            $last = $parts[count($parts) - 1];

            // A whole name, or a name welded together the way a login is: only
            // the person it belongs to produces these.
            foreach ([$name, str_replace(' ', '', $name), mb_substr($first, 0, 1) . $last, $first . mb_substr($last, 0, 1)] as $form) {
                if ($form !== '') {
                    $strong[$form][$id] = $id;
                }
            }

            // Half a name. Enough to offer on the screen, not enough to decide
            // by itself — half the office shares a first name with a driver.
            foreach ([$first, $last] as $form) {
                if ($form !== '') {
                    $weak[$form][$id] = $id;
                }
            }
        }

        $out = [];

        foreach ($keys as $key) {
            foreach ([$key, str_replace(' ', '', $key)] as $probe) {
                $hit = $strong[$probe] ?? [];

                if (count($hit) === 1) {
                    $out[$key] = ['driverId' => (int) array_values($hit)[0], 'strong' => true];

                    continue 2;
                }
            }

            foreach ([$key, str_replace(' ', '', $key)] as $probe) {
                $hit = $weak[$probe] ?? [];

                if (count($hit) === 1) {
                    $out[$key] = ['driverId' => (int) array_values($hit)[0], 'strong' => false];

                    continue 2;
                }
            }
        }

        return $out;
    }
    /**
     * @return array<string, array{driverId: int|null, office: bool, auto: bool}>
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        if (! Schema::hasTable('limo_driver_aliases')) {
            return $this->map = [];
        }

        $out = [];

        foreach (LimoDriverAlias::query()->get() as $row) {
            $out[(string) $row->alias] = [
                'driverId' => $row->driver_id === null ? null : (int) $row->driver_id,
                'office' => (bool) $row->is_office,
                'auto' => (bool) $row->auto,
            ];
        }

        return $this->map = $out;
    }

    /**
     * @return array<int, string>
     */
    private function driverNames(): array
    {
        if ($this->driverNames !== null) {
            return $this->driverNames;
        }

        if (! Schema::hasTable('rental_drivers')) {
            return $this->driverNames = [];
        }

        $out = [];

        foreach (LimoDriver::query()->get(['id', 'name']) as $driver) {
            $out[(int) $driver->getKey()] = (string) $driver->name;
        }

        return $this->driverNames = $out;
    }

    private function earlier(string $held, string $candidate): string
    {
        if ($candidate === '') {
            return $held;
        }

        return $held === '' || $candidate < $held ? $candidate : $held;
    }

    private function later(string $held, string $candidate): string
    {
        if ($candidate === '') {
            return $held;
        }

        return $held === '' || $candidate > $held ? $candidate : $held;
    }
}

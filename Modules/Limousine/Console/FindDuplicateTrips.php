<?php

declare(strict_types=1);

namespace Modules\Limousine\Console;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Throwable;

/**
 * Read-only. Looks for trips that may have been imported more than once, so a
 * suspected duplicate-import problem can be confirmed (or ruled out) from real
 * data before anything is changed. Writes nothing, ever.
 *
 * Three checks, all mirroring the same "same real trip" heuristic
 * {@see \Modules\Limousine\Support\LegacyBookingImporter}'s own TWIN guard
 * already uses at import time — this just runs it AFTER the fact, across
 * everything already on file, so it also catches pairs that came in through
 * two different tools/runs that never cross-checked each other:
 *
 *  1. **Exact duplicate bookings** — same customer, the identical pickup
 *     timestamp, the identical fare. About as close to a copy-paste as data
 *     gets.
 *  2. **Near-duplicate bookings** — same customer and fare, pickup times
 *     within a couple of minutes of each other. Catches the same mistake when
 *     a re-export shifted a timestamp by a minute. Skips a pair where either
 *     side's notes read "Booking #…" — that is a deliberate, already-tracked
 *     cross-reference (a trip re-entered in the ERP pointing back at its old
 *     booking), not an accidental double import.
 *  3. **Duplicate legs on the same booking** — two legs on one booking that
 *     share the same route, time and rate. A single trip should not have two
 *     identical legs.
 *
 * The customer+time+fare fingerprint alone cannot tell an accidental
 * duplicate apart from a legitimate MULTI-VEHICLE group booking — a wedding
 * or a company outing often books many real cars under one customer account,
 * all at the same scheduled time and the same per-car fare. Found on the
 * live Wanaan data (2026-09-21): dozens of "exact duplicate" groups were
 * really one event with a different passenger and a different driver on
 * every row. So each group (1) and (2) finds is further split into
 * "accidental-looking" (every row shares the same, or blank, passenger name
 * AND driver) vs. "probably a legitimate multi-vehicle booking" (a different
 * passenger or driver on at least one row) — only the first bucket counts
 * toward the headline total; both are still printed in full so nothing is
 * hidden.
 */
final class FindDuplicateTrips extends Command
{
    protected $signature = 'limo:find-duplicate-trips {--workspace= : Only this workspace id}';

    protected $description = 'Report trips that look like they were imported more than once. Read-only — changes nothing.';

    /** Two bookings within this many minutes of each other are "near". */
    private const NEAR_MINUTES = 2;

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
                    $this->reportHere();

                    continue;
                }

                $path = $workspace->databasePath();

                if ($path === null || ! is_file($path)) {
                    $this->warn('  skipped: its database file is missing.');

                    continue;
                }

                $workspaces->withTenant($path, function (): void {
                    $this->reportHere();
                });
            } catch (Throwable $e) {
                // One workspace failing must never stop the rest.
                $this->warn('  skipped: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function reportHere(): void
    {
        if (! Schema::hasTable('limo_bookings') || ! Schema::hasTable('limo_legs')) {
            $this->line('  Limousine is not installed here.');

            return;
        }

        $bookingCount = LimoBooking::query()->count();
        $legCount = LimoLeg::query()->where('legable_type', LimoBooking::class)->count();
        $maxLegReference = (int) LimoLeg::query()
            ->where('legable_type', LimoBooking::class)
            ->max('id');

        $this->line(sprintf(
            '  %d bookings, %d trips on file (highest trip reference: %d).',
            $bookingCount,
            $legCount,
            $maxLegReference > 0 ? LimoLeg::REFERENCE_START - 1 + $maxLegReference : 0,
        ));

        $exact = $this->exactDuplicateBookings();
        $near = $this->nearDuplicateBookings($exact);
        $legTwins = $this->duplicateLegsOnSameBooking();

        $extra = 0;

        if ($exact === []) {
            $this->line('  No exact duplicate bookings (same customer, timestamp and fare).');
        } else {
            $extra += $this->reportBookingGroups('EXACT duplicate', $exact);
        }

        if ($near === []) {
            $this->line('  No near-duplicate bookings (same customer/fare, pickup within ' . self::NEAR_MINUTES . ' minutes).');
        } else {
            $extra += $this->reportBookingGroups('NEAR-duplicate', $near);
        }

        if ($legTwins === []) {
            $this->line('  No booking has two identical legs.');
        } else {
            $this->warn(sprintf('  %d booking(s) with a duplicate leg on the same booking:', count($legTwins)));
            foreach ($legTwins as $row) {
                $extra++;
                $this->line(sprintf(
                    '    %s — trips %s and %s are identical (%s → %s at %s, %s BHD)',
                    $row['booking_ref'],
                    $row['ref_a'],
                    $row['ref_b'],
                    $row['from'],
                    $row['to'],
                    $row['start_at'],
                    $row['rate'],
                ));
            }
        }

        $this->line(sprintf(
            '  Total: %d row(s) look like accidental extras, out of %d bookings and %d trips.',
            $extra,
            $bookingCount,
            $legCount,
        ));
    }

    /**
     * A group only counts toward the headline "accidental extras" total when
     * nothing in it tells the rows apart — a different passenger or driver
     * on each row usually means a legitimate multi-vehicle group booking
     * (several cars sent out under one customer account for one job), not
     * the same trip entered twice. Both buckets are still printed, in full,
     * so nothing is hidden — only the COUNT that gets treated as a real
     * duplicate is narrowed.
     *
     * @param  list<list<array{id: int, reference: ?string, pax_name: ?string, notes: ?string, pickup_at: ?string, fare: float}>>  $groups
     */
    private function reportBookingGroups(string $kindLabel, array $groups): int
    {
        $genuine = [];
        $groupish = [];

        foreach ($groups as $group) {
            if ($this->looksGenuinelyDuplicated($group)) {
                $genuine[] = $group;
            } else {
                $groupish[] = $group;
            }
        }

        $this->warn(sprintf(
            '  %d group(s) of %s bookings - %d look accidental, %d look like legitimate multi-vehicle bookings (different passenger or driver):',
            count($groups),
            $kindLabel,
            count($genuine),
            count($groupish),
        ));

        if ($genuine !== []) {
            $this->line('  Accidental-looking (same or blank passenger/driver on every row):');
            foreach ($genuine as $group) {
                $this->printGroup($group);
            }
        }

        if ($groupish !== []) {
            $this->line('  Probably NOT duplicates - a different passenger or driver on each row (a multi-vehicle group booking):');
            foreach ($groupish as $group) {
                $this->printGroup($group);
            }
        }

        $extra = 0;
        foreach ($genuine as $group) {
            $extra += count($group) - 1;
        }

        return $extra;
    }

    /**
     * @return list<list<array{id: int, reference: ?string, pax_name: ?string, notes: ?string, pickup_at: ?string, fare: float}>>
     */
    private function exactDuplicateBookings(): array
    {
        $rows = LimoBooking::query()
            ->select(['id', 'reference', 'customer_id', 'pax_name', 'notes', 'pickup_at', 'fare'])
            ->whereNotNull('customer_id')
            ->whereNotNull('pickup_at')
            ->orderBy('customer_id')
            ->orderBy('pickup_at')
            ->get();

        $groups = [];
        foreach ($rows->groupBy(fn (LimoBooking $b): string => $this->fingerprint($b)) as $group) {
            if ($group->count() > 1) {
                $groups[] = $group->map(fn (LimoBooking $b): array => $this->asRow($b))->all();
            }
        }

        return $groups;
    }

    /**
     * @param  list<list<array{id: int}>>  $exact  Already-found exact groups, so the same pair is not reported twice.
     * @return list<list<array{id: int, reference: ?string, pax_name: ?string, notes: ?string, pickup_at: ?string, fare: float}>>
     */
    private function nearDuplicateBookings(array $exact): array
    {
        $alreadyReported = [];
        foreach ($exact as $group) {
            foreach ($group as $row) {
                $alreadyReported[$row['id']] = true;
            }
        }

        $bookings = LimoBooking::query()
            ->select(['id', 'reference', 'customer_id', 'pax_name', 'notes', 'pickup_at', 'fare'])
            ->whereNotNull('customer_id')
            ->whereNotNull('pickup_at')
            ->orderBy('customer_id')
            ->orderBy('pickup_at')
            ->get()
            ->groupBy('customer_id');

        $groups = [];

        foreach ($bookings as $forOneCustomer) {
            /** @var list<LimoBooking> $list */
            $list = $forOneCustomer->values()->all();
            $count = count($list);
            $used = [];

            for ($i = 0; $i < $count; $i++) {
                if (isset($used[$i]) || isset($alreadyReported[$list[$i]->id]) || $list[$i]->pickup_at === null) {
                    continue;
                }

                $cluster = [$list[$i]];

                for ($j = $i + 1; $j < $count; $j++) {
                    if (isset($alreadyReported[$list[$j]->id]) || $list[$j]->pickup_at === null) {
                        continue;
                    }

                    $minutesApart = abs($list[$i]->pickup_at->diffInSeconds($list[$j]->pickup_at)) / 60;
                    if ($minutesApart > self::NEAR_MINUTES) {
                        break; // sorted by pickup_at — nothing further can be closer
                    }

                    $sameFare = abs((float) $list[$i]->fare - (float) $list[$j]->fare) <= 0.001;
                    $isCrossReference = str_starts_with((string) $list[$i]->notes, 'Booking #')
                        || str_starts_with((string) $list[$j]->notes, 'Booking #');

                    if ($sameFare && ! $isCrossReference) {
                        $cluster[] = $list[$j];
                        $used[$j] = true;
                    }
                }

                if (count($cluster) > 1) {
                    $groups[] = array_map(fn (LimoBooking $b): array => $this->asRow($b), $cluster);
                }
            }
        }

        return $groups;
    }

    /**
     * @return list<array{booking_ref: string, ref_a: string, ref_b: string, from: string, to: string, start_at: string, rate: string}>
     */
    private function duplicateLegsOnSameBooking(): array
    {
        $legs = LimoLeg::query()
            ->select(['id', 'legable_id', 'reference', 'from_location', 'to_location', 'start_at', 'rate'])
            ->where('legable_type', LimoBooking::class)
            ->orderBy('legable_id')
            ->get()
            ->groupBy('legable_id');

        $out = [];

        foreach ($legs as $bookingId => $forOneBooking) {
            if ($forOneBooking->count() < 2) {
                continue;
            }

            $seen = [];
            foreach ($forOneBooking as $leg) {
                $key = strtolower(trim((string) $leg->from_location)) . '|'
                    . strtolower(trim((string) $leg->to_location)) . '|'
                    . (string) ($leg->start_at?->toDateTimeString() ?? '') . '|'
                    . number_format((float) $leg->rate, 3);

                if (isset($seen[$key])) {
                    $booking = LimoBooking::query()->find($bookingId);
                    $out[] = [
                        // $booking->reference already carries the "BK/" prefix
                        // (HasReference) — the fallback adds it explicitly so
                        // printGroup() below never needs to know which case it is.
                        'booking_ref' => (string) ($booking->reference ?? ('BK/' . $bookingId)),
                        'ref_a' => (string) ($seen[$key]->reference ?? $seen[$key]->id),
                        'ref_b' => (string) ($leg->reference ?? $leg->id),
                        'from' => (string) $leg->from_location,
                        'to' => (string) $leg->to_location,
                        'start_at' => (string) ($leg->start_at?->toDateTimeString() ?? '?'),
                        'rate' => number_format((float) $leg->rate, 3),
                    ];

                    continue;
                }

                $seen[$key] = $leg;
            }
        }

        return $out;
    }

    /** Groups on the same identity {@see \Modules\Limousine\Support\LegacyBookingImporter}'s TWIN check uses: customer, exact pickup instant, exact fare. */
    private function fingerprint(LimoBooking $b): string
    {
        return ((string) $b->customer_id) . '|'
            . ((string) ($b->pickup_at?->toDateTimeString() ?? '')) . '|'
            . number_format((float) $b->fare, 3);
    }

    /** Extracts "Driver: NAME" from the free-text notes the legacy importer stuffed in, or null when absent. */
    private function driverFromNotes(?string $notes): ?string
    {
        if ($notes === null || $notes === '' || preg_match('/driver:\s*([^|]+)/i', $notes, $m) !== 1) {
            return null;
        }

        $driver = trim($m[1]);

        return $driver === '' ? null : $driver;
    }

    /**
     * A group is only "genuinely" duplicated when nothing in it tells the
     * rows apart. A booking taken for a group event (a wedding, a company
     * outing) often has one customer, one shared pickup time and one fixed
     * per-car fare across MANY real bookings — exactly what the fingerprint
     * above matches on — but a different passenger or a different driver on
     * each row proves they are separate trips, not one entered twice.
     *
     * @param  list<array{id: int, reference: ?string, pax_name: ?string, notes: ?string, pickup_at: ?string, fare: float}>  $group
     */
    private function looksGenuinelyDuplicated(array $group): bool
    {
        $paxNames = [];
        $drivers = [];

        foreach ($group as $row) {
            $pax = strtolower(trim((string) $row['pax_name']));
            if ($pax !== '') {
                $paxNames[$pax] = true;
            }

            $driver = $this->driverFromNotes($row['notes']);
            if ($driver !== null) {
                $drivers[strtolower($driver)] = true;
            }
        }

        return count($paxNames) <= 1 && count($drivers) <= 1;
    }

    /** @return array{id: int, reference: ?string, pax_name: ?string, notes: ?string, pickup_at: ?string, fare: float} */
    private function asRow(LimoBooking $b): array
    {
        return [
            'id' => (int) $b->id,
            'reference' => $b->reference,
            'pax_name' => $b->pax_name,
            'notes' => $b->notes,
            'pickup_at' => $b->pickup_at?->toDateTimeString(),
            'fare' => (float) $b->fare,
        ];
    }

    /** @param list<array{id: int, reference: ?string, pax_name: ?string, notes: ?string, pickup_at: ?string, fare: float}> $group */
    private function printGroup(array $group): void
    {
        foreach ($group as $row) {
            $this->line(sprintf(
                '    %s — %s — %s BHD — pax: %s%s',
                $row['reference'] ?? ('#' . $row['id']),
                $row['pickup_at'] ?? '?',
                number_format($row['fare'], 3),
                $row['pax_name'] ?? '—',
                $row['notes'] !== null && $row['notes'] !== '' ? ' — notes: ' . $row['notes'] : '',
            ));
        }
        $this->line('');
    }
}

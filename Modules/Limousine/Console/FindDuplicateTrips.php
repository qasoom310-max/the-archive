<?php

declare(strict_types=1);

namespace Modules\Limousine\Console;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\DuplicateTripGroups;
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
 *
 * **Even the "accidental-looking" bucket is a heuristic, not a confirmed
 * defect list.** {@see \Modules\Limousine\Support\LegacyBookingImporter}'s own
 * TWIN guard deliberately never compares two already-imported legacy bookings
 * against each other, on the explicit reasoning (see its doc comment) that
 * two old-system booking numbers can legitimately mean two real trips (two
 * cars at one time). So a genuinely accidental double-entry in the OLD
 * system and a real multi-car dispatch under the same driver placeholder can
 * look identical from data alone. {@see ReviewDuplicateTrips} narrows this
 * further by checking whether either side already has money recorded
 * against it (an invoice or a receipt) — the strongest signal that two rows
 * are genuinely separate, separately-billed trips.
 */
final class FindDuplicateTrips extends Command
{
    protected $signature = 'limo:find-duplicate-trips {--workspace= : Only this workspace id}';

    protected $description = 'Report trips that look like they were imported more than once. Read-only — changes nothing.';

    public function handle(WorkspaceManager $workspaces, DuplicateTripGroups $finder): int
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
                    $this->reportHere($finder);

                    continue;
                }

                $path = $workspace->databasePath();

                if ($path === null || ! is_file($path)) {
                    $this->warn('  skipped: its database file is missing.');

                    continue;
                }

                $workspaces->withTenant($path, function () use ($finder): void {
                    $this->reportHere($finder);
                });
            } catch (Throwable $e) {
                // One workspace failing must never stop the rest.
                $this->warn('  skipped: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function reportHere(DuplicateTripGroups $finder): void
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

        $exact = $finder->exactGroups();
        $near = $finder->nearGroups($exact);
        $legTwins = $finder->legTwins();

        $extra = 0;

        if ($exact === []) {
            $this->line('  No exact duplicate bookings (same customer, timestamp and fare).');
        } else {
            $extra += $this->reportBookingGroups($finder, 'EXACT duplicate', $exact);
        }

        if ($near === []) {
            $this->line('  No near-duplicate bookings (same customer/fare, pickup within ' . DuplicateTripGroups::NEAR_MINUTES . ' minutes).');
        } else {
            $extra += $this->reportBookingGroups($finder, 'NEAR-duplicate', $near);
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
    private function reportBookingGroups(DuplicateTripGroups $finder, string $kindLabel, array $groups): int
    {
        $genuine = [];
        $groupish = [];

        foreach ($groups as $group) {
            if ($finder->looksGenuinelyDuplicated($group)) {
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

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
            $this->warn(sprintf('  %d group(s) of EXACT duplicate bookings:', count($exact)));
            foreach ($exact as $group) {
                $extra += count($group) - 1;
                $this->printGroup($group);
            }
        }

        if ($near === []) {
            $this->line('  No near-duplicate bookings (same customer/fare, pickup within ' . self::NEAR_MINUTES . ' minutes).');
        } else {
            $this->warn(sprintf('  %d group(s) of NEAR-duplicate bookings:', count($near)));
            foreach ($near as $group) {
                $extra += count($group) - 1;
                $this->printGroup($group);
            }
        }

        if ($legTwins === []) {
            $this->line('  No booking has two identical legs.');
        } else {
            $this->warn(sprintf('  %d booking(s) with a duplicate leg on the same booking:', count($legTwins)));
            foreach ($legTwins as $row) {
                $extra++;
                $this->line(sprintf(
                    '    BK/%s — trips %s and %s are identical (%s → %s at %s, %s BHD)',
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
                        'booking_ref' => (string) ($booking->reference ?? $bookingId),
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

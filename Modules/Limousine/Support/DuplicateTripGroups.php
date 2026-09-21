<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * The single source of truth for "which trips look like they were imported
 * more than once" — shared by the plain report
 * ({@see \Modules\Limousine\Console\FindDuplicateTrips}) and the detailed,
 * money-aware review shortlist
 * ({@see \Modules\Limousine\Console\ReviewDuplicateTrips}), so the two can
 * never disagree about what counts as a duplicate. Entirely read-only —
 * every method here only reads.
 */
final class DuplicateTripGroups
{
    /** Two bookings within this many minutes of each other are "near". */
    public const NEAR_MINUTES = 2;

    /**
     * @return list<list<array{id: int, reference: ?string, pax_name: ?string, notes: ?string, pickup_at: ?string, fare: float}>>
     */
    public function exactGroups(): array
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
    public function nearGroups(array $exact): array
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
    public function legTwins(): array
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
                        // callers never need to know which case it is.
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

    /**
     * A group is only "genuinely" duplicated when nothing in it tells the
     * rows apart. A booking taken for a group event (a wedding, a company
     * outing) often has one customer, one shared pickup time and one fixed
     * per-car fare across MANY real bookings — exactly what the fingerprint
     * below matches on — but a different passenger or a different driver on
     * each row proves they are separate trips, not one entered twice.
     *
     * @param  list<array{id: int, reference: ?string, pax_name: ?string, notes: ?string, pickup_at: ?string, fare: float}>  $group
     */
    public function looksGenuinelyDuplicated(array $group): bool
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

    /** Extracts "Driver: NAME" from the free-text notes the legacy importer stuffed in, or null when absent. */
    public function driverFromNotes(?string $notes): ?string
    {
        if ($notes === null || $notes === '' || preg_match('/driver:\s*([^|]+)/i', $notes, $m) !== 1) {
            return null;
        }

        $driver = trim($m[1]);

        return $driver === '' ? null : $driver;
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
}

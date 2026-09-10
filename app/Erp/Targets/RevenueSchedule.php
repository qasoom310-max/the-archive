<?php

declare(strict_types=1);

namespace App\Erp\Targets;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a period's money actually came from.
 *
 * A target box says "12,450 of 20,000" and stops there, which tells the owner
 * the score without telling them anything they can act on. This breaks the same
 * figure down by the thing that earned it: for Rent A Car that is the CAR,
 * because every car already carries its own monthly target, so the schedule and
 * the target are answering the same question at two levels. Limousine has no
 * vehicle register, so it groups by the type of car booked.
 *
 * The rows always reconcile to {@see RevenueTargets::earned()} for the same
 * window: same paid-only filter, same bounds, and everything outside the top
 * few is carried in an "others" bucket rather than dropped. A schedule that
 * does not add up to the headline above it is worse than no schedule.
 */
final class RevenueSchedule
{
    /** Rows shown before the rest is folded into "others". */
    private const TOP = 8;

    /**
     * @return array{
     *     rows: list<array{label: string, amount: float, jobs: int, target: float|null, pct: int|null, share: int}>,
     *     others: float, othersCount: int, total: float, dimension: string
     * }
     */
    public function forWindow(string $app, CarbonImmutable $from, CarbonImmutable $to, float $targetFactor = 1.0): array
    {
        return match ($app) {
            'rental' => $this->byCar($from, $to, $targetFactor),
            'limousine' => $this->byServiceType($from, $to),
            default => $this->empty('—'),
        };
    }

    /**
     * Rent A Car: one row per car, against that car's own monthly target.
     *
     * $targetFactor scales the per-car MONTHLY target to the window - 1 for a
     * month, 12 for a year - so a car's row is judged over the same stretch as
     * the box it sits under.
     *
     * @return array{rows: list<array{label: string, amount: float, jobs: int, target: float|null, pct: int|null, share: int}>, others: float, othersCount: int, total: float, dimension: string}
     */
    private function byCar(CarbonImmutable $from, CarbonImmutable $to, float $targetFactor): array
    {
        if (! Schema::hasTable('rental_orders')) {
            return $this->empty(__('Car'));
        }

        $rows = DB::table('rental_orders')
            ->where('payment_status', 'paid')
            ->whereBetween('start_date', RevenueTargets::windowBounds($from, $to))
            // Net of what outside vendors are paid, exactly as the box above.
            ->selectRaw('vehicle_id, COALESCE(SUM(total - outside_cost), 0) as amount, COUNT(*) as jobs')
            ->groupBy('vehicle_id')
            ->get();

        $cars = Schema::hasTable('rental_vehicles')
            ? DB::table('rental_vehicles')->get(['id', 'name', 'plate_no', 'monthly_target'])->keyBy('id')
            : collect();

        $mapped = [];

        foreach ($rows as $row) {
            $car = $row->vehicle_id === null ? null : ($cars[$row->vehicle_id] ?? null);
            $target = $car === null ? null : round((float) $car->monthly_target * $targetFactor, 3);

            $mapped[] = [
                // A car with no name still has a plate, and a job booked
                // against no car at all is real money that must not vanish.
                'label' => $car === null
                    ? __('No car recorded')
                    : trim(((string) $car->name).' '.($car->plate_no === null ? '' : '· '.$car->plate_no)),
                'amount' => round((float) $row->amount, 3),
                'jobs' => (int) $row->jobs,
                'target' => $target !== null && $target > 0.0 ? $target : null,
                'pct' => null,
                'share' => 0,
            ];
        }

        return $this->rank($mapped, __('Car'));
    }

    /**
     * Limousine: one row per kind of work - a transfer, a chauffeur day.
     *
     * This deliberately does NOT group by the car. The first cut used
     * `limo_bookings.car_type`, which no import ever filled, so a whole year of
     * takings landed in one row reading "No car type recorded, 100%" - a
     * breakdown that broke nothing and told nobody anything. `car_id` on the
     * leg is empty on historic data too. The service type is set on every leg
     * (it defaults to a transfer), so it is the one dimension here with real
     * data behind it.
     *
     * Grouping still happens over BOOKINGS, so the rows keep adding up to the
     * box above: the leg only says what kind of work a booking was, and the
     * money stays the booking's own fare.
     *
     * @return array{rows: list<array{label: string, amount: float, jobs: int, target: float|null, pct: int|null, share: int}>, others: float, othersCount: int, total: float, dimension: string}
     */
    private function byServiceType(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('limo_bookings')) {
            return $this->empty(__('Service'));
        }

        $bookings = DB::table('limo_bookings')
            ->where('payment_status', 'paid')
            ->whereBetween('pickup_at', RevenueTargets::windowBounds($from, $to))
            ->get(['id', 'fare']);

        if ($bookings->isEmpty()) {
            return $this->empty(__('Service'));
        }

        // A booking takes the kind of its FIRST leg. A job that starts as a
        // transfer and continues as a chauffeur day is one sale, and splitting
        // its fare between two rows would invent money that was never billed
        // that way.
        $kinds = [];

        if (Schema::hasTable('limo_legs')) {
            $legs = DB::table('limo_legs')
                ->where('legable_type', 'Modules\\Limousine\\Models\\LimoBooking')
                ->whereIn('legable_id', $bookings->pluck('id')->all())
                ->orderBy('sequence')
                ->get(['legable_id', 'service_type']);

            foreach ($legs as $leg) {
                $kinds[(int) $leg->legable_id] ??= (string) $leg->service_type;
            }
        }

        $totals = [];

        foreach ($bookings as $booking) {
            $kind = $kinds[(int) $booking->id] ?? '';
            // Mapped explicitly rather than prettifying the raw column, so the
            // labels can be translated and an unknown value is visible as
            // itself instead of being quietly title-cased into looking fine.
            $label = match ($kind) {
                'transfer' => __('Pick & drop (transfer)'),
                'chauffeur' => __('Chauffeur (hours)'),
                '' => __('Not recorded'),
                default => $kind,
            };

            $totals[$label] ??= ['amount' => 0.0, 'jobs' => 0];
            $totals[$label]['amount'] = round($totals[$label]['amount'] + (float) $booking->fare, 3);
            $totals[$label]['jobs']++;
        }

        $mapped = [];

        foreach ($totals as $label => $bucket) {
            $mapped[] = [
                'label' => (string) $label,
                'amount' => $bucket['amount'],
                'jobs' => $bucket['jobs'],
                // No vehicle register, so no per-row target to score against.
                'target' => null,
                'pct' => null,
                'share' => 0,
            ];
        }

        return $this->rank($mapped, __('Service'));
    }

    /**
     * Biggest earner first, the tail folded into one row so the column still
     * adds up to the figure in the box above it.
     *
     * @param  list<array{label: string, amount: float, jobs: int, target: float|null, pct: int|null, share: int}>  $rows
     * @return array{rows: list<array{label: string, amount: float, jobs: int, target: float|null, pct: int|null, share: int}>, others: float, othersCount: int, total: float, dimension: string}
     */
    private function rank(array $rows, string $dimension): array
    {
        $total = round(array_sum(array_column($rows, 'amount')), 3);

        usort($rows, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        $kept = array_slice($rows, 0, self::TOP);
        $tail = array_slice($rows, self::TOP);

        foreach ($kept as $i => $row) {
            $kept[$i]['share'] = $total > 0.0 ? (int) round($row['amount'] / $total * 100) : 0;
            $kept[$i]['pct'] = $row['target'] !== null && $row['target'] > 0.0
                ? (int) round($row['amount'] / $row['target'] * 100)
                : null;
        }

        return [
            'rows' => array_values($kept),
            'others' => round(array_sum(array_column($tail, 'amount')), 3),
            'othersCount' => count($tail),
            'total' => $total,
            'dimension' => $dimension,
        ];
    }

    /**
     * @return array{rows: list<array{label: string, amount: float, jobs: int, target: float|null, pct: int|null, share: int}>, others: float, othersCount: int, total: float, dimension: string}
     */
    private function empty(string $dimension): array
    {
        return ['rows' => [], 'others' => 0.0, 'othersCount' => 0, 'total' => 0.0, 'dimension' => $dimension];
    }
}

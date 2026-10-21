<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;

/**
 * What each OUTSIDE (commission) driver is owed for a period: every trip they
 * drove, with the commission agreed when the trip was given to them
 * (`limo_legs.driver_payment`, set in the assign dialog).
 *
 * A driver is listed when they are a commission driver today, or when any trip
 * in the period carries a commission for them (someone moved back to the
 * company's books still has those trips to be paid). Cancelled trips and
 * quotation legs are never counted. A trip given to a commission driver with
 * no amount still shows, flagged, so a missing figure is seen rather than
 * silently left out of the total.
 *
 * @phpstan-type Trip array{id: int, date: string, reference: string, booking: string, booking_id: int|null, customer: string, route: string, car: string, status: string, amount: float|null}
 * @phpstan-type DriverRow array{id: int, name: string, phone: string, usual: float|null, commission: bool, trips: list<Trip>, total: float, missing: int}
 */
final class OutsideDriverPayments
{
    public function __construct(
        private readonly Carbon $from,
        private readonly Carbon $to,
        private readonly string $search = '',
    ) {}

    /**
     * @return array{drivers: list<DriverRow>, total: float, trips: int, missing: int}
     */
    public function report(): array
    {
        $from = $this->from->copy()->startOfDay();
        $to = $this->to->copy()->endOfDay();

        $legs = LimoLeg::query()
            ->where('legable_type', LimoBooking::class)
            ->where('status', '!=', LimoLeg::STATUS_CANCELLED)
            ->whereNotNull('driver_id')
            ->whereBetween('start_at', [$from, $to])
            ->with('legable.customer')
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();

        $paidDriverIds = $legs->whereNotNull('driver_payment')->pluck('driver_id')->map(static fn (mixed $id): int => (int) $id)->unique()->all();

        $drivers = LimoDriver::query()
            ->where(static function ($q) use ($paidDriverIds): void {
                $q->where('pay_type', LimoDriver::PAY_TYPE_COMMISSION);
                if ($paidDriverIds !== []) {
                    $q->orWhereIn('id', $paidDriverIds);
                }
            })
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderBy('name')
            ->get();

        $byDriver = $legs->groupBy('driver_id');
        $rows = [];

        foreach ($drivers as $driver) {
            $commission = $driver->isCommissionDriver();
            $trips = [];

            foreach ($byDriver->get($driver->id, collect()) as $leg) {
                /** @var LimoLeg $leg */
                // A company driver's trips only count where a commission was
                // actually agreed; a commission driver's all count.
                if (! $commission && $leg->driver_payment === null) {
                    continue;
                }
                $trips[] = $this->trip($leg);
            }

            $amounts = array_filter(array_column($trips, 'amount'), static fn (?float $a): bool => $a !== null);

            $rows[] = [
                'id' => (int) $driver->id,
                'name' => $driver->displayName(),
                'phone' => (string) ($driver->phone ?? ''),
                'usual' => $driver->commission_amount !== null ? (float) $driver->commission_amount : null,
                'commission' => $commission,
                'trips' => $trips,
                'total' => round(array_sum($amounts), 3),
                'missing' => count($trips) - count($amounts),
            ];
        }

        // Who is owed the most first; drivers with nothing in the period last.
        usort($rows, static fn (array $a, array $b): int => [count($b['trips']) > 0, $b['total'], $a['name']] <=> [count($a['trips']) > 0, $a['total'], $b['name']]);

        return [
            'drivers' => $rows,
            'total' => round(array_sum(array_column($rows, 'total')), 3),
            'trips' => array_sum(array_map(static fn (array $r): int => count($r['trips']), $rows)),
            'missing' => array_sum(array_column($rows, 'missing')),
        ];
    }

    /**
     * @return Trip
     */
    private function trip(LimoLeg $leg): array
    {
        $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;

        $route = $leg->service_type === LimoLeg::TYPE_CHAUFFEUR
            ? trim(__('Chauffeur').' · '.__(':hours hours', ['hours' => (int) ($leg->hours ?? 0)]).' · '.__('from').' '.(string) ($leg->from_location ?? ''))
            : trim((string) ($leg->from_location ?? '').' → '.(string) ($leg->to_location ?? ''));

        return [
            'id' => (int) $leg->id,
            'date' => $leg->start_at?->format('j-M-Y g:i A') ?? '',
            'reference' => (string) ($leg->reference ?? ''),
            'booking' => (string) ($booking->reference ?? ''),
            'booking_id' => $booking?->id !== null ? (int) $booking->id : null,
            'customer' => (string) ($booking->customer->name ?? ''),
            'route' => $route,
            'car' => trim((string) ($leg->vehicle ?: $leg->vehicle_details ?: '')),
            'status' => (string) $leg->status,
            'amount' => $leg->driver_payment !== null ? round((float) $leg->driver_payment, 3) : null,
        ];
    }
}

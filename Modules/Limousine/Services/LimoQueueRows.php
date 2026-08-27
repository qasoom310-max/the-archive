<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * The one definition of a queue row.
 *
 * The screen and all five exports (Copy / CSV / Excel / PDF / Print) render the
 * SAME rows from here. Building the columns separately per format is how an
 * export quietly starts disagreeing with what the office is looking at — the
 * kind of drift nobody notices until a printed sheet is wrong.
 *
 * A row is a LEG, because that is the unit dispatched. Money is deliberately
 * mixed: `amount` is the leg's own price, while `received` and `balance` come
 * from the parent booking, since the customer settles the whole job rather than
 * a leg of it.
 *
 * @phpstan-type QueueRow array{
 *     leg_id: int, booking_id: int, reference: string, booking_reference: string,
 *     from_date: string, to_date: string, type: string, customer: string,
 *     amount: float, received: float, balance: float, pickup: string, dropoff: string,
 *     vehicle: string, driver: string, added_by: string, comments: string, booked_time: string,
 *     status: string, payment: string
 * }
 */
final class LimoQueueRows
{
    /**
     * Base query behind both the screen and the exports.
     *
     * @return Builder<LimoLeg>
     */
    public function query(string $tab = 'all', string $from = '', string $to = '', string $search = ''): Builder
    {
        $query = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->with(['legable.customer:id,name'])
            ->orderByDesc('start_at')
            ->orderBy('sequence');

        if (in_array($tab, [
            LimoLeg::STATUS_QUEUE,
            LimoLeg::STATUS_CONFIRMED,
            LimoLeg::STATUS_ACTIVE,
            LimoLeg::STATUS_COMPLETED,
            LimoLeg::STATUS_CANCELLED,
        ], true)) {
            $query->where('status', $tab);
        }

        if ($from !== '') {
            $query->whereDate('start_at', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('start_at', '<=', $to);
        }

        $term = trim($search);
        if ($term !== '') {
            // Everything the office would reach for: the leg's own number, the
            // booking's, the customer, the passenger and the route. Grouped in a
            // closure so the ORs cannot escape and widen the tab/date filters
            // above into an "or match anything" query.
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

            $query->where(function (Builder $q) use ($like): void {
                $q->where('reference', 'like', $like)
                    ->orWhere('from_location', 'like', $like)
                    ->orWhere('to_location', 'like', $like)
                    ->orWhere('vehicle', 'like', $like)
                    ->orWhere('driver', 'like', $like)
                    ->orWhereHasMorph('legable', LimoBooking::class, function ($booking) use ($like): void {
                        $booking->where('reference', 'like', $like)
                            ->orWhere('pax_name', 'like', $like)
                            ->orWhere('pax_contact', 'like', $like)
                            ->orWhere('flight_number', 'like', $like)
                            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like));
                    });
            });
        }

        return $query;
    }

    /**
     * @return LengthAwarePaginator<int, LimoLeg>
     */
    public function paginate(string $tab, string $from, string $to, string $search = '', int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($tab, $from, $to, $search)->paginate($perPage);
    }

    /**
     * Flatten a leg into the columns every format prints.
     *
     * @return QueueRow
     */
    public function row(LimoLeg $leg): array
    {
        $booking = $this->bookingOf($leg);

        return [
            'leg_id' => (int) $leg->id,
            'booking_id' => (int) $leg->legable_id,
            'reference' => (string) ($leg->reference ?? ''),
            'booking_reference' => (string) ($booking->reference ?? ''),
            'from_date' => $leg->start_at?->isoFormat('DD-MMM-YY HH:mm') ?? '',
            'to_date' => $this->endsAt($leg),
            'type' => $leg->service_type === LimoLeg::TYPE_CHAUFFEUR ? __('Chauffeur') : __('Transfer'),
            'customer' => (string) ($booking->customer->name ?? ''),
            // The leg's own price; the money below is the whole booking's.
            'amount' => round((float) $leg->net_amount, 3),
            'received' => round((float) ($booking->advance ?? 0), 3),
            'balance' => round($booking?->balanceDue() ?? 0.0, 3),
            'pickup' => (string) ($leg->from_location ?? ''),
            'dropoff' => (string) ($leg->to_location ?? ''),
            'vehicle' => (string) ($leg->vehicle ?? ''),
            'driver' => (string) ($leg->driver ?? ''),
            'added_by' => (string) ($booking->prepared_by ?? ''),
            'comments' => (string) ($booking->notes ?? ''),
            'booked_time' => $booking?->created_at?->isoFormat('DD-MMM-YY HH:mm') ?? '',
            'status' => (string) ($leg->status ?? ''),
            'payment' => (string) ($booking->payment_status ?? ''),
        ];
    }

    /**
     * The booking a leg hangs off, or null.
     *
     * The query filters to booking legs, so in practice this always finds one —
     * but `legable` is a morph with no foreign key, so a leg whose parent has
     * been deleted would otherwise fatal a whole page of rows. The declared
     * nullable return type is also what keeps the null branch visible to static
     * analysis, which reads the relation as non-nullable.
     */
    private function bookingOf(LimoLeg $leg): ?LimoBooking
    {
        $parent = $leg->legable;

        return $parent instanceof LimoBooking ? $parent : null;
    }

    /**
     * When the leg finishes.
     *
     * A transfer is a single run, so it ends when it starts. A chauffeur booking
     * holds the car for `days` consecutive days, which is what the old system's
     * "To Date" showed.
     */
    private function endsAt(LimoLeg $leg): string
    {
        if ($leg->start_at === null) {
            return '';
        }

        if ($leg->service_type !== LimoLeg::TYPE_CHAUFFEUR) {
            return $leg->start_at->isoFormat('DD-MMM-YY HH:mm');
        }

        return $leg->start_at->copy()
            ->addDays(max(0, (int) $leg->days - 1))
            ->isoFormat('DD-MMM-YY HH:mm');
    }

    /**
     * Every row for the current filter, for the exports. Chunked so a long
     * queue doesn't load the whole table into memory at once.
     *
     * @return list<QueueRow>
     */
    public function all(string $tab, string $from, string $to, string $search = ''): array
    {
        $rows = [];
        $this->query($tab, $from, $to, $search)->chunk(200, function ($legs) use (&$rows): void {
            foreach ($legs as $leg) {
                $rows[] = $this->row($leg);
            }
        });

        return $rows;
    }

    /**
     * Column headings, in print order. Shared so the screen and every export
     * label the same data the same way.
     *
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'),
            'from_date' => __('From date'),
            'to_date' => __('To date'),
            'type' => __('Type'),
            'customer' => __('Customer'),
            'amount' => __('Amount'),
            'received' => __('Received'),
            'balance' => __('Balance'),
            'pickup' => __('Pickup'),
            'dropoff' => __('Drop off'),
            'vehicle' => __('Vehicle'),
            'driver' => __('Driver'),
            'added_by' => __('Added by'),
            'comments' => __('Comments'),
            'booked_time' => __('Booked time'),
            'status' => __('Status'),
            'payment' => __('Payment'),
        ];
    }
}

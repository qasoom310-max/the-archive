<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\DriverAliases;

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
 *     from_date: string, to_date: string, type: string, customer: string, customer_id: int,
 *     amount: float, received: float, balance: float, pickup: string, dropoff: string,
 *     vehicle: string, driver: string, added_by: string, comments: string, booked_time: string,
 *     status: string, payment: string
 * }
 */
final class LimoQueueRows
{
    /**
     * Money still to collect — a tab rather than a status, because being owed
     * for a trip is not a stage the trip is at. A job can be queued, driven or
     * finished and still unpaid.
     */
    public const TAB_UNPAID = 'unpaid';

    /**
     * The columns the queue can be ordered by — every column it prints except
     * Sl No., which is only the position in the list it is already in.
     *
     * @var list<string>
     */
    public const SORTS = [
        'reference', 'from_date', 'to_date', 'type', 'customer', 'amount',
        'received', 'balance', 'pickup', 'dropoff', 'vehicle', 'driver',
        'added_by', 'comments', 'booked_time', 'status', 'payment',
    ];

    /**
     * Base query behind both the screen and the exports.
     *
     * @return Builder<LimoLeg>
     */
    public function query(string $tab = 'all', string $from = '', string $to = '', string $search = '', string $sort = '', string $dir = 'desc'): Builder
    {
        $query = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->with(['legable.customer:id,name,type,phone'])
            // Sorting can reach through to the parent booking, which means a
            // join, which means two tables offering `id`, `reference`, `status`
            // and `notes`. Select the legs explicitly so the model is always
            // hydrated from its own row whatever gets joined on.
            ->select(($legs = $this->table(LimoLeg::class)) . '.*');

        $this->applySort($query, $sort, $dir);

        if (in_array($tab, [
            LimoLeg::STATUS_QUEUE,
            LimoLeg::STATUS_CONFIRMED,
            LimoLeg::STATUS_ACTIVE,
            LimoLeg::STATUS_COMPLETED,
            LimoLeg::STATUS_CANCELLED,
        ], true)) {
            $query->where($legs . '.status', $tab);
        }

        if ($tab === self::TAB_UNPAID) {
            $this->onlyUnpaid($query);
        }

        if ($from !== '') {
            $query->whereDate($legs . '.start_at', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate($legs . '.start_at', '<=', $to);
        }

        $term = trim($search);
        if ($term !== '') {
            // Everything the office would reach for: the leg's own number, the
            // booking's, the customer, the passenger and the route. Grouped in a
            // closure so the ORs cannot escape and widen the tab/date filters
            // above into an "or match anything" query.
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

            $query->where(function (Builder $q) use ($like, $legs): void {
                $q->where($legs . '.reference', 'like', $like)
                    ->orWhere($legs . '.from_location', 'like', $like)
                    ->orWhere($legs . '.to_location', 'like', $like)
                    ->orWhere($legs . '.vehicle', 'like', $like)
                    ->orWhere($legs . '.driver', 'like', $like)
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
     * Trips on a booking that still owes money.
     *
     * Payment belongs to the BOOKING — the customer settles the job, not a leg
     * of it — so this asks the parent through the morph rather than joining,
     * which keeps it clear of whatever join a sort may already have added.
     *
     * Cancelled trips are left out: they are not work waiting to be paid for,
     * and a called-off trip is no longer billed anyway. `fare > advance` as well
     * as the flag, so a booking priced at nothing never sits in a list of money
     * to chase.
     *
     * @param  Builder<LimoLeg>  $query
     */
    private function onlyUnpaid(Builder $query): void
    {
        $query
            ->where($this->table(LimoLeg::class) . '.status', '!=', LimoLeg::STATUS_CANCELLED)
            ->whereHasMorph('legable', LimoBooking::class, function ($booking): void {
                $booking->where('payment_status', LimoBooking::PAYMENT_UNPAID)
                    ->whereColumn('fare', '>', 'advance');
            });
    }

    /** How many trips are waiting to be paid for — the tab's badge. */
    public function unpaidCount(): int
    {
        $query = LimoLeg::query()->whereMorphedTo('legable', LimoBooking::class);
        $this->onlyUnpaid($query);

        return $query->count();
    }

    /**
     * A model's table name, so a join never hard-codes one.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function table(string $model): string
    {
        return (new $model())->getTable();
    }

    /**
     * Order the queue by a column the user clicked.
     *
     * In SQL, not over the fetched page: sorting twenty rows of a hundred by
     * amount would put the largest of THIS page on top and call it the largest.
     * The list is of legs, but half of what it prints belongs to the parent
     * booking — Customer, Received, Balance, Booked time — so those sorts join
     * through the morph rather than giving up and sorting by something else.
     *
     * Leg sequence and id trail every sort so the order is total: two trips at
     * the same minute, or two blanks in the same column, never swap places
     * between one page and the next.
     *
     * @param  Builder<LimoLeg>  $query
     */
    private function applySort(Builder $query, string $sort, string $dir): void
    {
        $legs = $this->table(LimoLeg::class);
        $bookings = $this->table(LimoBooking::class);
        $direction = $dir === 'asc' ? 'asc' : 'desc';

        if (! in_array($sort, self::SORTS, true)) {
            $query->orderByDesc($legs . '.start_at');
            $query->orderBy($legs . '.sequence')->orderBy($legs . '.id');

            return;
        }

        /** @var array{0: string, 1: Expression|string} $target */
        $target = match ($sort) {
            'reference' => ['', $legs . '.reference'],
            'from_date' => ['', $legs . '.start_at'],
            'to_date' => ['', $this->endsAtColumn()],
            'amount' => ['', $legs . '.net_amount'],
            'pickup' => ['', $legs . '.from_location'],
            'dropoff' => ['', $legs . '.to_location'],
            'vehicle' => ['', $legs . '.vehicle'],
            'driver' => ['', $legs . '.driver'],
            'status' => ['', $legs . '.status'],
            'customer' => ['customer', $this->table(LimoCustomer::class) . '.name'],
            'type' => ['booking', $bookings . '.booking_type'],
            'received' => ['booking', $bookings . '.advance'],
            'balance' => ['booking', $this->balanceColumn()],
            'added_by' => ['booking', $bookings . '.prepared_by'],
            'comments' => ['booking', $bookings . '.notes'],
            'booked_time' => ['booking', $bookings . '.created_at'],
            default => ['booking', $bookings . '.payment_status'],
        };

        [$join, $column] = $target;

        if ($join !== '') {
            $query->leftJoin($bookings, $bookings . '.id', '=', $legs . '.legable_id');
        }

        if ($join === 'customer') {
            $customers = $this->table(LimoCustomer::class);
            $query->leftJoin($customers, $customers . '.id', '=', $bookings . '.customer_id');
        }

        $query->orderBy($column, $direction);
        $query->orderBy($legs . '.sequence')->orderBy($legs . '.id');
    }

    /**
     * When a trip ends, as SQL can order it.
     *
     * A transfer ends the day it starts; a chauffeur job runs `days` of them.
     * That is what the To date column prints, so it is what clicking that
     * column has to order by — sorting a column by a different value than the
     * one it shows is a lie the user has no way to see. Falls back to the start
     * on any driver that cannot do the arithmetic, which is still right for
     * every single-day trip.
     */
    private function endsAtColumn(): Expression|string
    {
        $legs = $this->table(LimoLeg::class);

        return match (DB::connection((new LimoLeg())->getConnectionName())->getDriverName()) {
            'sqlite' => DB::raw("datetime({$legs}.start_at, '+' || ({$legs}.days - 1) || ' days')"),
            'mysql', 'mariadb' => DB::raw("date_add({$legs}.start_at, interval ({$legs}.days - 1) day)"),
            default => $legs . '.start_at',
        };
    }

    /**
     * What is still owed, as SQL can order it: the fare less what was taken,
     * floored at zero exactly as {@see LimoBooking::balanceDue()} floors it, so
     * an overpaid booking sorts as the settled 0.00 the column shows.
     */
    private function balanceColumn(): Expression
    {
        $b = $this->table(LimoBooking::class);

        return DB::raw("case when {$b}.fare - {$b}.advance < 0 then 0 else {$b}.fare - {$b}.advance end");
    }

    /**
     * @return LengthAwarePaginator<int, LimoLeg>
     */
    public function paginate(string $tab, string $from, string $to, string $search = '', int $perPage = 20, string $sort = '', string $dir = 'desc'): LengthAwarePaginator
    {
        return $this->query($tab, $from, $to, $search, $sort, $dir)->paginate($perPage);
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
            'type' => $this->tripType($leg, $booking),
            'customer' => (string) ($booking->customer->name ?? ''),
            // Not a printed column — the screen links the name to the
            // customer's account page. Exports read by heading, so an extra
            // key here never reaches a spreadsheet.
            'customer_id' => (int) ($booking->customer_id ?? 0),
            // The leg's own price; the money below is the whole booking's.
            'amount' => round((float) $leg->net_amount, 3),
            'received' => round((float) ($booking->advance ?? 0), 3),
            'balance' => round($booking?->balanceDue() ?? 0.0, 3),
            'pickup' => (string) ($leg->from_location ?? ''),
            'dropoff' => (string) ($leg->to_location ?? ''),
            'vehicle' => (string) ($leg->vehicle ?? ''),
            // A trip carried over names the old system's login; once that
            // login has been matched, the person is shown in its place.
            'driver' => app(DriverAliases::class)->resolve((string) ($leg->driver ?? ''))['name'],
            'added_by' => (string) ($booking->prepared_by ?? ''),
            'comments' => (string) ($booking->notes ?? ''),
            'booked_time' => $booking?->created_at?->isoFormat('DD-MMM-YY HH:mm') ?? '',
            'status' => (string) ($leg->status ?? ''),
            'payment' => (string) ($booking->payment_status ?? ''),
        ];
    }

    /**
     * What kind of job this is, in the office's own words.
     *
     * The booking's type is the real answer — Airport transfer, Hourly, Full
     * day — because that is what was agreed with the customer. The leg's
     * `service_type` only says how it is priced (transfer vs chauffeur) and
     * defaults to transfer, so reading it alone made every row say "Transfer"
     * whatever the job actually was. Falls back to it when a booking has no
     * type set, so the column is never blank.
     */
    private function tripType(LimoLeg $leg, ?LimoBooking $booking): string
    {
        $type = $booking?->booking_type;

        if (is_string($type) && $type !== '') {
            foreach (LimoBooking::bookingTypeOptions() as $option) {
                if ($option['value'] === $type) {
                    return (string) __($option['label']);
                }
            }

            return $type;
        }

        return $leg->service_type === LimoLeg::TYPE_CHAUFFEUR ? (string) __('Chauffeur') : (string) __('Transfer');
    }

    /**
     * The trip as a WhatsApp message — what the office actually sends a driver
     * or a customer.
     *
     * Built server-side so the text is identical everywhere it is copied, and
     * ordered the way it is read on a phone: which job, when, for whom, what to
     * collect, where from and to, which car. `*stars*` are WhatsApp's bold.
     * Empty fields are dropped rather than sent as blank labels.
     */
    public function whatsappText(LimoLeg $leg): string
    {
        $booking = $this->bookingOf($leg);
        $customer = $booking?->customer;

        $lines = [];
        $lines[] = '*' . __('Ref. #') . ' ' . ($leg->reference ?? '') . '*';

        if ($leg->start_at !== null) {
            $lines[] = $leg->start_at->isoFormat('DD-MMM-YY') . ' · ' . $leg->start_at->isoFormat('hh:mm A');
        }

        $lines[] = __('Type') . ': ' . $this->tripType($leg, $booking);

        if ($customer !== null) {
            $who = (string) $customer->name;
            $phone = (string) ($customer->phone ?? '');
            $lines[] = __('Customer') . ': ' . ($phone !== '' ? $who . ' - ' . $phone : $who);
        }

        // Money the driver has to handle is the line that must not be missed, so
        // it sits above the route rather than buried at the end — but only for
        // an individual: a company's trips are settled on its account, not by
        // the driver collecting cash from whoever is riding, so neither "collect
        // from customer" nor "Paid" applies and the line is left out entirely.
        if ($customer === null || ! $customer->isCompany()) {
            $balance = round($booking?->balanceDue() ?? 0.0, 3);
            if ($balance > 0.001) {
                $method = $booking !== null ? trim((string) ($booking->payment_method ?? '')) : '';
                $collect = __('Balance :amount BD — collect from customer', ['amount' => number_format($balance, 3)]);
                $lines[] = '*' . ($method !== '' ? $collect . ' ' . __('in') . ' ' . ucfirst($method) : $collect) . '*';
            } else {
                $lines[] = '✅ ' . __('Paid');
            }
        }

        $lines[] = '';
        $lines[] = __('Pick up') . ': ' . ($leg->from_location ?? '');
        if (($leg->from_location_url ?? '') !== '') {
            $lines[] = (string) $leg->from_location_url;
        }

        if (($leg->to_location ?? '') !== '') {
            $lines[] = __('Drop off') . ': ' . $leg->to_location;
            if (($leg->to_location_url ?? '') !== '') {
                $lines[] = (string) $leg->to_location_url;
            }
        }

        $car = trim((string) ($leg->vehicle ?? ''));
        if ($car !== '') {
            $lines[] = '';
            $lines[] = __('Car') . ': *' . $car . '*';
        }

        return implode("\n", $lines);
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
    public function all(string $tab, string $from, string $to, string $search = '', string $sort = '', string $dir = 'desc'): array
    {
        $rows = [];
        // `chunk` needs a total order to page through safely, which every sort
        // has: applySort always trails sequence and id.
        $this->query($tab, $from, $to, $search, $sort, $dir)->chunk(200, function ($legs) use (&$rows): void {
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

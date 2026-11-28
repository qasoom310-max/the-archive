<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use App\Erp\Search\PhoneSearch;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\DriverAliases;
use Modules\Limousine\Support\LiveEntry;
use Modules\Limousine\Support\PlaceCountry;
use Modules\Limousine\Support\TripDetailsLock;

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
 *     from_date: string, to_date: string, eta: string, type: string, customer: string, customer_id: int, passenger: string, contact_phone: string,
 *     amount: float, received: float, balance: float, booking_balance: float, pickup: string, dropoff: string,
 *     vehicle: string, vehicle_type: string, driver: string, added_by: string, comments: string, booked_time: string,
 *     status: string, payment: string
 * }
 */
final class LimoQueueRows
{
    /** Built on first use: it loads the saved places once per list, not per row. */
    private ?PlaceCountry $places = null;

    /**
     * Money still to collect — a tab rather than a status, because being owed
     * for a trip is not a stage the trip is at. A job can be queued, driven or
     * finished and still unpaid.
     */
    public const TAB_UNPAID = 'unpaid';

    /** The driver-tab key for trips with no driver yet. */
    public const DRIVER_NONE = 'none';

    /**
     * A whole day, whatever stage its trips reached — what the schedule
     * cards on the app home open, and the "All" tab on the bookings list.
     * Cancelled trips are left out unless searching; they have their own tab.
     */
    public const TAB_ALL = 'all';

    /**
     * Trips that should have a car and a driver by now and don't — the
     * customer is promised both 12 hours before pick-up. Still to happen and
     * picking up within the next day, or picked up in the last one (so a trip
     * that slipped through is still in front of the office). Relative to now,
     * not a date range.
     */
    public const TAB_NEEDS_CREW = 'needs_crew';

    /** The two kinds of customer the Unpaid list can be narrowed to. */
    public const KIND_COMPANY = 'company';

    public const KIND_INDIVIDUAL = 'individual';

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
    public function query(string $tab = 'all', string $from = '', string $to = '', string $search = '', string $sort = '', string $dir = 'desc', bool $onlyLive = false, string $driver = '', string $customerKind = '', int $customerId = 0): Builder
    {
        $query = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            // What each booking's invoices record as paid, so the row can tell
            // paid from part paid without asking once per trip.
            // In this order: the nested load would otherwise replace the
            // constraint on `legable` with a blank one.
            ->with('legable.customer:id,name,type,phone')
            ->with('legable', function (Relation $legable): void {
                if ($legable instanceof MorphTo) {
                    // With its trips, so each row can show its own share of the money.
                    $legable->constrain([LimoBooking::class => static fn (Builder $b): Builder => LimoBooking::withInvoicesPaidOn($b)
                        ->with('legs:id,legable_type,legable_id,sequence,status,refund_outcome,net_amount')]);
                }
            })
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

        if ($tab === self::TAB_NEEDS_CREW) {
            $this->onlyNeedingCrew($query);
        }

        // A called-off trip is not happening, so it is not part of a day's
        // work and does not belong in the list a schedule card opens. The
        // Cancelled tab still holds every one of them.
        //
        // A SEARCH is a lookup rather than a plan — the customer page links
        // here to show one customer's whole history — so a typed term brings
        // them back, still badged Cancelled. Every other tab asks for one
        // status by name and is unaffected either way.
        if ($tab === self::TAB_ALL && trim($search) === '') {
            // `status` is nullable, and SQL says a NULL is neither equal nor
            // unequal to anything — so a bare `!= cancelled` would silently
            // drop every leg that has no status yet along with the cancelled
            // ones. Ask for both halves.
            $query->where(function (Builder $q) use ($legs): void {
                $q->where($legs . '.status', '!=', LimoLeg::STATUS_CANCELLED)
                    ->orWhereNull($legs . '.status');
            });
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

            $query->where(function (Builder $q) use ($like, $legs, $term): void {
                $q->where($legs . '.reference', 'like', $like)
                    // The number a trip had before it was renumbered.
                    ->orWhere($legs . '.previous_reference', 'like', $like)
                    ->orWhere($legs . '.from_location', 'like', $like)
                    ->orWhere($legs . '.to_location', 'like', $like)
                    ->orWhere($legs . '.vehicle', 'like', $like)
                    ->orWhere($legs . '.driver', 'like', $like)
                    ->orWhereHasMorph('legable', LimoBooking::class, function ($booking) use ($like, $term): void {
                        // A phone number is matched on its digits — typed with
                        // a country code or spaces it still finds the trip,
                        // the customer's own number as well as the passenger's.
                        $phone = PhoneSearch::ending($term);
                        $booking->where('reference', 'like', $like)
                            ->orWhere('pax_name', 'like', $like)
                            ->orWhere('pax_contact', 'like', $like)
                            ->orWhere('flight_number', 'like', $like)
                            ->orWhereHas('customer', function ($c) use ($like, $phone): void {
                                $c->where('name', 'like', $like)->orWhere('phone', 'like', $like);
                                if ($phone !== null) {
                                    PhoneSearch::orWhere($c, 'phone', $phone);
                                }
                            });
                        if ($phone !== null) {
                            $booking->orWhere(fn ($q) => PhoneSearch::orWhere($q, 'pax_contact', $phone));
                        }
                    });
            });
        }

        // The historical CSV import backdates `imported_at` to the real moment
        // it ran; nothing else touches that column. "Live" therefore means the
        // parent booking was entered through the ERP itself (or the ongoing
        // Import button), not brought over in the one-time historical migration.
        if ($onlyLive) {
            $query->whereHasMorph('legable', LimoBooking::class, function (Builder $q): void {
                $q->whereNull('imported_at')
                    ->where('created_at', '>=', LiveEntry::since());
            });
        }

        if ($driver !== '') {
            $this->onlyDriver($query, $driver);
        }

        if ($customerKind !== '' || $customerId > 0) {
            $this->onlyCustomer($query, $customerKind, $customerId);
        }

        return $query;
    }

    /**
     * One kind of customer (companies, or everyone else), or one customer.
     *
     * A customer is a company or it is not: a booking with no customer on it,
     * or one whose customer has no kind recorded, counts with the individuals,
     * so the two kinds always add up to the whole list. An unknown kind matches
     * nothing rather than quietly showing every trip.
     *
     * @param  Builder<LimoLeg>  $query
     */
    private function onlyCustomer(Builder $query, string $kind, int $customerId): void
    {
        if (! in_array($kind, ['', self::KIND_COMPANY, self::KIND_INDIVIDUAL], true)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHasMorph('legable', LimoBooking::class, function (Builder $booking) use ($kind, $customerId): void {
            if ($customerId > 0) {
                $booking->where('customer_id', $customerId);
            }
            if ($kind === self::KIND_COMPANY) {
                $booking->whereHas('customer', fn (Builder $c) => $c->where('type', LimoCustomer::TYPE_COMPANY));
            } elseif ($kind === self::KIND_INDIVIDUAL) {
                $booking->whereDoesntHave('customer', fn (Builder $c) => $c->where('type', LimoCustomer::TYPE_COMPANY));
            }
        });
    }

    /**
     * Who owes money on the Unpaid list, split into companies and individuals.
     *
     * Read from the same query as the list (same dates, same search), so the
     * number on a box is the number of customers whose trips are listed under
     * it. A booking is counted once however many trips it has: `balance` is
     * what the customer still owes across their bookings, `trips` how many
     * rows of the list are theirs.
     *
     * @return array<string, list<array{id: int, name: string, trips: int, balance: float}>>
     */
    public function unpaidCustomers(string $from = '', string $to = '', string $search = ''): array
    {
        $legs = $this->table(LimoLeg::class);
        $tripsPerBooking = [];
        foreach ($this->query(self::TAB_UNPAID, $from, $to, $search)->reorder()->toBase()->pluck($legs . '.legable_id') as $bookingId) {
            $tripsPerBooking[(int) $bookingId] = ($tripsPerBooking[(int) $bookingId] ?? 0) + 1;
        }

        $kinds = [self::KIND_COMPANY => [], self::KIND_INDIVIDUAL => []];
        foreach (array_chunk(array_keys($tripsPerBooking), 500) as $ids) {
            $bookings = LimoBooking::withInvoicesPaidOn(LimoBooking::query()->whereIn('id', $ids))
                ->with('customer:id,name,type')
                ->get();

            foreach ($bookings as $booking) {
                $customer = $booking->customer;
                $kind = $customer !== null && $customer->isCompany() ? self::KIND_COMPANY : self::KIND_INDIVIDUAL;
                $id = $customer !== null ? (int) $customer->id : 0;

                $kinds[$kind][$id] ??= [
                    'id' => $id,
                    'name' => $customer !== null ? (string) $customer->name : __('No customer'),
                    'trips' => 0,
                    'balance' => 0.0,
                ];
                $kinds[$kind][$id]['trips'] += $tripsPerBooking[(int) $booking->id] ?? 0;
                $kinds[$kind][$id]['balance'] = round($kinds[$kind][$id]['balance'] + $booking->amountOwed(), 3);
            }
        }

        // Whoever owes the most first: that is who to ring first.
        foreach ($kinds as $kind => $list) {
            $list = array_values($list);
            usort($list, static fn (array $a, array $b): int => [-$a['balance'], mb_strtolower($a['name'])] <=> [-$b['balance'], mb_strtolower($b['name'])]);
            $kinds[$kind] = $list;
        }

        return $kinds;
    }

    /**
     * One driver's trips, by the key {@see drivers()} hands out: `none` = no
     * driver yet, `d12` = driver record 12, `n:Habib` = a name typed on an old
     * trip with no record behind it. An unknown key matches nothing rather than
     * quietly showing every trip.
     *
     * @param  Builder<LimoLeg>  $query
     */
    private function onlyDriver(Builder $query, string $driver): void
    {
        $legs = $this->table(LimoLeg::class);

        if ($driver === self::DRIVER_NONE) {
            $query->whereNull($legs . '.driver_id')
                ->where(fn (Builder $q) => $q->whereNull($legs . '.driver')->orWhere($legs . '.driver', ''));
        } elseif (preg_match('/^d(\d+)$/', $driver, $m) === 1) {
            $query->where($legs . '.driver_id', (int) $m[1]);
        } elseif (str_starts_with($driver, 'n:') && trim(substr($driver, 2)) !== '') {
            $query->whereNull($legs . '.driver_id')->where($legs . '.driver', trim(substr($driver, 2)));
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * The drivers on the trips a list shows, each with how many trips they
     * have, for the driver tabs of a day page. Trips with no driver come first
     * (`none`), then the busiest driver. Read with one query of two columns.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function drivers(string $tab, string $from, string $to, string $search = ''): array
    {
        $legs = $this->table(LimoLeg::class);
        $pairs = $this->query($tab, $from, $to, $search)
            ->reorder()
            ->toBase()
            ->select([$legs . '.driver_id', $legs . '.driver'])
            ->get();

        $counts = [];
        $typed = [];
        foreach ($pairs as $pair) {
            $id = $pair->driver_id !== null ? (int) $pair->driver_id : null;
            $text = trim((string) ($pair->driver ?? ''));
            if ($id !== null) {
                $key = 'd' . $id;
            } elseif ($text !== '') {
                $key = 'n:' . $text;
                $typed[$key] = $text;
            } else {
                $key = self::DRIVER_NONE;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $ids = [];
        foreach (array_keys($counts) as $key) {
            if (preg_match('/^d(\d+)$/', (string) $key, $m) === 1) {
                $ids[] = (int) $m[1];
            }
        }
        $names = $ids === [] ? collect() : \Modules\Limousine\Models\LimoDriver::query()->whereIn('id', $ids)->pluck('name', 'id');
        $aliases = app(DriverAliases::class);

        $list = [];
        foreach ($counts as $key => $count) {
            $key = (string) $key;
            $label = match (true) {
                $key === self::DRIVER_NONE => __('No driver'),
                isset($typed[$key]) => $aliases->resolve($typed[$key])['name'],
                default => (string) ($names[(int) substr($key, 1)] ?? __('Driver removed')),
            };
            $list[] = ['key' => $key, 'label' => $label, 'count' => $count];
        }

        usort($list, static fn (array $a, array $b): int => [$a['key'] !== self::DRIVER_NONE, -$a['count'], mb_strtolower($a['label'])]
            <=> [$b['key'] !== self::DRIVER_NONE, -$b['count'], mb_strtolower($b['label'])]);

        return $list;
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
            ->whereHasMorph('legable', LimoBooking::class, function (Builder $booking): void {
                // Paid in part or not at all, whatever the flag says.
                LimoBooking::owingOn($booking);
            });
    }

    /**
     * See {@see TAB_NEEDS_CREW}.
     *
     * @param  Builder<LimoLeg>  $query
     */
    private function onlyNeedingCrew(Builder $query): void
    {
        $legs = $this->table(LimoLeg::class);
        $now = now();

        $query
            ->whereIn($legs . '.status', LimoLeg::OPEN_STATUSES)
            ->whereBetween($legs . '.start_at', [$now->copy()->subDay(), $now->copy()->addHours(LimoLeg::CREW_PROMISE_HOURS * 2)])
            ->where(static fn (Builder $q) => LimoLeg::whereMissingCrew($q, $legs . '.'));
    }

    /** How many trips are waiting for a car or a driver — that tab's badge. */
    public function needsCrewCount(): int
    {
        $query = LimoLeg::query()->whereMorphedTo('legable', LimoBooking::class);
        $this->onlyNeedingCrew($query);

        return $query->count();
    }

    /** How many trips are waiting to be paid for — the tab's badge. */
    public function unpaidCount(string $from = '', string $to = ''): int
    {
        $query = LimoLeg::query()->whereMorphedTo('legable', LimoBooking::class);
        $this->onlyUnpaid($query);
        // Bounded by pick-up date, like the list (a day or month page).
        if ($from !== '') {
            $query->whereDate('start_at', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('start_at', '<=', $to);
        }

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

        // Trip numbers are text: plain string order puts "41697" above
        // "200001". Shorter numbers are smaller numbers.
        if ($sort === 'reference') {
            $query->orderByRaw('LENGTH(' . $legs . '.reference) ' . ($direction === 'desc' ? 'desc' : 'asc'));
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
        // What was received, read exactly as LimoBooking::amountReceived().
        $received = LimoBooking::receivedSql($b);

        return DB::raw("case when {$b}.fare - {$received} < 0 then 0 else {$b}.fare - {$received} end");
    }

    /**
     * @return LengthAwarePaginator<int, LimoLeg>
     */
    public function paginate(string $tab, string $from, string $to, string $search = '', int $perPage = 20, string $sort = '', string $dir = 'desc', string $driver = '', string $customerKind = '', int $customerId = 0): LengthAwarePaginator
    {
        return $this->query($tab, $from, $to, $search, $sort, $dir, false, $driver, $customerKind, $customerId)->paginate($perPage);
    }

    /**
     * Phone for the row: the passenger's for a company customer, the
     * customer's own otherwise.
     */
    /** The passenger's name on a company booking, unless it only repeats the company's. */
    private function passenger(?LimoBooking $booking): string
    {
        $customer = $booking?->customer;
        if ($customer === null || ! $customer->isCompany()) {
            return '';
        }

        $pax = trim((string) ($booking->pax_name ?? ''));

        return strcasecmp($pax, trim((string) $customer->name)) === 0 ? '' : $pax;
    }

    private function contactPhone(?LimoBooking $booking): string
    {
        $customer = $booking?->customer;
        if ($customer !== null && $customer->isCompany()) {
            return trim((string) ($booking->pax_contact ?? ''));
        }

        return trim((string) ($customer->phone ?? ''));
    }

    /**
     * Flatten a leg into the columns every format prints.
     *
     * @return QueueRow
     */
    public function row(LimoLeg $leg): array
    {
        $booking = $this->bookingOf($leg);

        // An operations user the owner named sees where, who and the phone
        // numbers only once the trip has a car and a driver — on screen and in
        // every export alike, since both are built here. See TripDetailsLock.
        $hidden = TripDetailsLock::hides($leg);

        return [
            'leg_id' => (int) $leg->id,
            'booking_id' => (int) $leg->legable_id,
            'reference' => (string) ($leg->reference ?? ''),
            'booking_reference' => (string) ($booking->reference ?? ''),
            'from_date' => $leg->start_at?->isoFormat('DD-MMM-YY HH:mm') ?? '',
            'to_date' => $this->endsAt($leg),
            // Not a printed column (exports keep their order): the screen
            // shows it on the Queue, Active and Completed tabs.
            'eta' => $leg->eta()?->isoFormat('DD-MMM-YY HH:mm') ?? '',
            'type' => $this->tripType($leg, $booking),
            'customer' => (string) ($booking->customer->name ?? ''),
            // Not a printed column — the screen links the name to the
            // customer's account page. Exports read by heading, so an extra
            // key here never reaches a spreadsheet.
            'customer_id' => (int) ($booking->customer_id ?? 0),
            // The number to ring, shown under the name (not a printed column).
            // A company is not who gets in the car, so for one it is the
            // passenger's number; for a person it is their own.
            'contact_phone' => $hidden ? '' : $this->contactPhone($booking),
            // Who rides, under a COMPANY's name: the account is not the person
            // the driver meets. Not a printed column, like contact_phone.
            'passenger' => $hidden ? '' : $this->passenger($booking),
            // The trip's own price, and the trip's own part of the money
            // (LimoBooking::tripShares): two trips booked together no longer
            // each show the whole booking's balance.
            'amount' => round((float) $leg->net_amount, 3),
            'received' => $booking?->tripShares()[(int) $leg->id]['received'] ?? 0.0,
            'balance' => $booking?->tripShares()[(int) $leg->id]['owed'] ?? 0.0,
            // The whole booking's balance, for the screen's tooltip. Not a
            // printed column: exports read by heading.
            'booking_balance' => $booking?->amountOwed() ?? 0.0,
            'pickup' => $hidden ? TripDetailsLock::place($leg->from_country) : (string) ($leg->from_location ?? ''),
            'dropoff' => $hidden && trim((string) ($leg->to_location ?? '')) !== ''
                ? TripDetailsLock::place($leg->to_country)
                : (string) ($leg->to_location ?? ''),
            // Country codes the screen shows above each place (KSA over
            // "Dammam"). Not printed columns. A held-back trip already names
            // its country in place of the place, so it gets none.
            'pickup_country' => $hidden ? '' : $this->countryCode($leg->from_country, $leg->from_location),
            'dropoff_country' => $hidden || trim((string) ($leg->to_location ?? '')) === ''
                ? ''
                : $this->countryCode($leg->to_country, $leg->to_location),
            'vehicle' => (string) ($leg->vehicle ?? ''),
            // Another company's car (one of our drivers drives it): the company
            // shows under the car. Not a printed column.
            'outside_company' => $leg->hasOutsideCar() ? trim((string) $leg->outside_company) : '',
            // What was ASKED for — Sedan, SUV, Vito — which is known from the
            // moment the trip is booked, long before a particular car is free
            // to run it. The leg's own note wins; `car_type` is where the old
            // system kept it, so imported bookings still answer. Not a printed
            // column: exports read by heading, so this never reaches a sheet.
            'vehicle_type' => $this->vehicleType($leg, $booking),
            // A trip carried over names the old system's login; once that
            // login has been matched, the person is shown in its place.
            'driver' => app(DriverAliases::class)->resolve((string) ($leg->driver ?? ''))['name'],
            'added_by' => (string) ($booking->prepared_by ?? ''),
            'comments' => $hidden ? '' : (string) ($booking->notes ?? ''),
            'booked_time' => $booking?->created_at?->isoFormat('DD-MMM-YY HH:mm') ?? '',
            // paid / partial / unpaid, read from the money (LimoBooking::paymentState()).
            'payment' => $booking?->tripPaymentState((int) $leg->id) ?? '',
            'status' => (string) ($leg->status ?? ''),
        ];
    }

    /**
     * The short code the office uses for a place's country: KSA for Saudi
     * Arabia, UAE for the Emirates, the ISO code otherwise (BH, KW, QA, OM).
     * An older trip has no stored country, so it is read from the place.
     */
    private function countryCode(?string $stored, ?string $place): string
    {
        $this->places ??= new PlaceCountry();
        $iso = $this->places->of($stored, $place);

        return match ($iso) {
            'SA' => 'KSA',
            'AE' => 'UAE',
            \Modules\Limousine\Models\LimoLocation::OTHER_COUNTRY => __('Other'),
            default => $iso,
        };
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
    public function tripType(LimoLeg $leg, ?LimoBooking $booking): string
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
        // Nothing to copy for a trip held back from this user: it is exactly
        // the message that went to drivers before anyone was assigned.
        if (TripDetailsLock::hides($leg)) {
            return '';
        }

        // A trip with its driver gets only the short message and his trip link
        // (the owner's wording, 2026-11-24): everything else is on the page,
        // where he also records his KM, fuel and location. A trip without a
        // driver yet keeps the full message, which is how one is offered round.
        $tripLink = \Modules\Limousine\Services\DriverTrip::ready() ? \Modules\Limousine\Support\DriverTripLink::short($leg) : null;
        if ($tripLink !== null) {
            $bills = \Illuminate\Support\Facades\Schema::hasTable('limo_driver_bills') ? \Modules\Limousine\Support\DriverBillLink::short($leg) : null;

            return \Modules\Limousine\Support\DriverTripLink::message($leg, $tripLink, $bills);
        }

        $booking = $this->bookingOf($leg);
        $customer = $booking?->customer;

        $lines = [];
        $lines[] = '*' . __('Ref. #') . ' ' . ($leg->reference ?? '') . '*';

        if ($leg->start_at !== null && $leg->service_type === LimoLeg::TYPE_CHAUFFEUR) {
            // A chauffeur job holds the car for a stretch, so the driver needs
            // when it ends as well as when it starts.
            $lines = [...$lines, ...$this->chauffeurWindow($leg)];
        } elseif ($leg->start_at !== null) {
            // 24-hour, like the queue's own date columns and the rest of the
            // office's paperwork — a driver reading 05:30 off a message should
            // never have to work out which half of the day it means.
            $lines[] = $leg->start_at->isoFormat('DD-MMM-YY') . ' · ' . $leg->start_at->isoFormat('HH:mm');
        }

        $lines[] = __('Type') . ': ' . $this->tripType($leg, $booking);

        if ($customer !== null) {
            $who = (string) $customer->name;
            $phone = (string) ($customer->phone ?? '');
            $lines[] = __('Customer') . ': ' . ($phone !== '' ? $who . ' - ' . $phone : $who);
        }

        // The passenger is who the driver actually meets, and on a company
        // booking is rarely the account the trip is billed to — so their name
        // and number sit right under the customer's. Either half alone still
        // prints; both missing drops the line.
        $pax = trim((string) ($booking->pax_name ?? ''));
        $paxContact = trim((string) ($booking->pax_contact ?? ''));
        if ($pax !== '' || $paxContact !== '') {
            $lines[] = __('PAX') . ': ' . ($pax !== '' && $paxContact !== ''
                ? $pax . ' - ' . $paxContact
                : ($pax !== '' ? $pax : $paxContact));
        }

        // Meet & greet: the driver (or whoever meets them) holds this name.
        if ($booking?->meet_greet) {
            $lines[] = __('Meet & greet') . ': ' . trim((string) ($booking->meet_greet_name ?? ''));
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

        // The car TYPE asked for is known from the moment the trip is booked;
        // a particular car is only assigned later, often the morning of the
        // trip. Printing only the assigned one left most messages with no car
        // at all, so the type comes first and the assigned car follows once
        // there is one (unless it just repeats the type).
        $type = $this->vehicleType($leg, $booking);
        $car = trim((string) ($leg->vehicle ?? ''));
        if ($type !== '' || $car !== '') {
            $lines[] = '';
        }
        if ($type !== '') {
            $lines[] = __('Car type') . ': *' . $type . '*';
        }
        if ($car !== '' && strcasecmp($car, $type) !== 0) {
            $lines[] = __('Car') . ': *' . $car . '*';
        }

        // The office's comments are instructions for the driver (meet at gate
        // 3, child seat…), so they travel with the trip: the booking's
        // Comments, then this trip's own note when it says something else.
        $notes = array_values(array_unique(array_filter([
            trim((string) ($booking->notes ?? '')),
            trim((string) ($leg->notes ?? '')),
        ], static fn (string $n): bool => $n !== '')));
        if ($notes !== []) {
            $lines[] = '';
            $lines[] = __('Comments') . ': ' . implode("\n", $notes);
        }

        // Money goes LAST, where the office asked for it — it is the note they
        // finish the message on. Every trip says whether it is paid, part paid
        // or unpaid. Only a private customer gets the amount to collect: a
        // company's trips are settled on its account, not by the driver taking
        // cash from whoever is riding, so no figure is printed for one.
        // This trip's part only: a driver collects for the trip he drives.
        $balance = $booking?->tripShares()[(int) $leg->id]['owed'] ?? 0.0;
        $state = $booking?->tripPaymentState((int) $leg->id) ?? LimoBooking::PAYMENT_PAID;
        $lines[] = '';

        if ($state === LimoBooking::PAYMENT_PAID) {
            $lines[] = '✅ ' . __('Paid');
        } else {
            $lines[] = $state === LimoBooking::PAYMENT_PARTIAL
                ? '🟡 ' . __('Part paid')
                : '❌ ' . __('Unpaid');

            if ($customer === null || ! $customer->isCompany()) {
                $method = $booking !== null ? trim((string) ($booking->payment_method ?? '')) : '';
                $collect = __('Balance :amount BD — collect from customer', ['amount' => number_format($balance, 3)]);
                $lines[] = '*' . ($method !== '' ? $collect . ' ' . __('in') . ' ' . ucfirst($method) : $collect) . '*';
            }
        }

        // The driver photographs his own bills for this trip through this
        // link (48 hours). Only once a driver is on the trip: it is his link.
        $billLink = \Modules\Limousine\Support\DriverBillLink::for($leg);
        if ($billLink !== null && \Illuminate\Support\Facades\Schema::hasTable('limo_driver_bills')) {
            $lines[] = '';
            $lines[] = '📸 ' . __('Your bills for this trip (link works 48 hours)') . ':';
            $lines[] = $billLink;
            $lines[] = '⚠️ ' . \Modules\Limousine\Support\DriverBillLink::NOTE_EN;
            $lines[] = '⚠️ ' . \Modules\Limousine\Support\DriverBillLink::NOTE_AR;
        }

        return implode("\n", $lines);
    }

    /**
     * The trip's details for the "Booking done successfully" banner: when,
     * what kind of trip, who rides, from and to, and which car. The office
     * reads it back to the customer, so no money, phone numbers or driver
     * notes go in. Empty fields are dropped.
     *
     * @return list<string>
     */
    public function confirmationLines(LimoLeg $leg): array
    {
        $booking = $this->bookingOf($leg);
        $lines = [];

        if ($leg->start_at !== null && $leg->service_type === LimoLeg::TYPE_CHAUFFEUR) {
            $lines = $this->chauffeurWindow($leg);
        } elseif ($leg->start_at !== null) {
            $lines[] = __('Date') . ': ' . $leg->start_at->isoFormat('DD-MMM-YY') . ' · ' . $leg->start_at->isoFormat('HH:mm');
        }

        $lines[] = __('Type') . ': ' . $this->tripType($leg, $booking);

        // Held back from this user until the trip has a car and a driver.
        if (TripDetailsLock::hides($leg)) {
            $lines[] = TripDetailsLock::placeholder();

            return $lines;
        }

        $pax = trim((string) ($booking->pax_name ?? ''));
        if ($pax !== '') {
            $lines[] = __('PAX') . ': ' . $pax;
        }

        if (trim((string) ($leg->from_location ?? '')) !== '') {
            $lines[] = __('Pick up') . ': ' . trim((string) $leg->from_location);
        }
        if (trim((string) ($leg->to_location ?? '')) !== '') {
            $lines[] = __('Drop off') . ': ' . trim((string) $leg->to_location);
        }

        $type = $this->vehicleType($leg, $booking);
        if ($type !== '') {
            $lines[] = __('Car type') . ': ' . $type;
        }

        return $lines;
    }

    /** The car type asked for: the leg's own note first, else the booking's field (where the old system kept it). */
    public function vehicleType(LimoLeg $leg, ?LimoBooking $booking): string
    {
        $details = trim((string) ($leg->vehicle_details ?? ''));

        return $details !== '' ? $details : trim((string) ($booking->car_type ?? ''));
    }

    /**
     * Start, end and length of a chauffeur job: `hours` a day across `days`
     * consecutive days, so it ends on the last day, `hours` after the start
     * time (past midnight when it runs late).
     *
     * @return list<string>
     */
    public function chauffeurWindow(LimoLeg $leg): array
    {
        $start = $leg->start_at;
        if ($start === null) {
            return [];
        }

        $stamp = static fn (\Carbon\CarbonInterface $at): string => $at->isoFormat('DD-MMM-YY') . ' · ' . $at->isoFormat('HH:mm');
        $days = max(1, (int) $leg->days);
        $hours = (float) ($leg->hours ?? 0);

        $lines = [__('Start') . ': ' . $stamp($start)];
        if ($hours <= 0) {
            if ($days > 1) {
                $lines[] = __('End') . ': ' . $start->copy()->addDays($days - 1)->isoFormat('DD-MMM-YY');
            }

            return $lines;
        }

        $end = $start->copy()->addDays($days - 1)->addMinutes((int) round($hours * 60));
        $length = trans_choice(':count hour|:count hours', $hours == floor($hours) ? (int) $hours : 2, ['count' => rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.')]);
        if ($days > 1) {
            $length .= ' × ' . trans_choice(':count day|:count days', $days, ['count' => $days]);
        }
        $lines[] = __('End') . ': ' . $stamp($end) . ' (' . $length . ')';

        return $lines;
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
    public function bookingOf(LimoLeg $leg): ?LimoBooking
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
    public function all(string $tab, string $from, string $to, string $search = '', string $sort = '', string $dir = 'desc', bool $onlyLive = false, string $driver = '', string $customerKind = '', int $customerId = 0): array
    {
        $rows = [];
        // `chunk` needs a total order to page through safely, which every sort
        // has: applySort always trails sequence and id.
        $this->query($tab, $from, $to, $search, $sort, $dir, $onlyLive, $driver, $customerKind, $customerId)->chunk(200, function ($legs) use (&$rows): void {
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
            'payment' => __('Payment'),
            'status' => __('Status'),
        ];
    }
}

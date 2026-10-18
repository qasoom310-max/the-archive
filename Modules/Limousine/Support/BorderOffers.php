<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use App\Models\Pricing\PricingSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * The cross-border empty-car offer: when a car has to cross between Bahrain
 * and another country EMPTY anyway, a customer going the same way, at a
 * time that fits, gets a discount (30% by default).
 *
 * Where the cars will be comes from the limousine bookings:
 *
 * - A trip that ENDS outside Bahrain leaves a car there ("supply"). Unless
 *   something else uses it, it drives back empty: a return run from that
 *   country to Bahrain, leaving any time from its arrival until it has waited
 *   `border_wait_hours`.
 * - A trip that STARTS outside Bahrain needs a car there ("demand"). Unless a
 *   car is already there, one drives out empty: a run from Bahrain to that
 *   country, leaving so it arrives at the pick-up having waited no longer
 *   than `border_wait_hours`.
 * - A supply and a demand in the same country cancel out: the same car (the
 *   two trips are on one assigned car) or any car that arrives and is needed
 *   again within the waiting time. That is also how a booked offer stops
 *   being offered: the customer's trip is a demand that uses up the car.
 *
 * The office's times are Bahrain wall-clock times, and the app runs in the
 * company timezone, so every time here is compared as entered.
 */
final class BorderOffers
{
    public const HOME = 'BH';

    /** Offered on the website, home first. */
    public const COUNTRIES = ['BH', 'SA'];

    /** @var array<string, array{en: string, ar: string}> */
    public const COUNTRY_NAMES = [
        'BH' => ['en' => 'Bahrain', 'ar' => 'البحرين'],
        'SA' => ['en' => 'Saudi Arabia', 'ar' => 'السعودية'],
    ];

    /** How far ahead a customer may search. */
    public const MAX_DAYS_AHEAD = 90;

    /** "Next offers" look this far ahead and show at most this many. */
    public const UPCOMING_DAYS = 14;

    public const UPCOMING_LIMIT = 6;

    /**
     * The customer's own estimate of their time on the road, in whole hours
     * (Khobar is about 1, Riyadh about 5). It replaces `border_travel_hours`
     * for THEIR trip: they must arrive in time for the car's next job.
     */
    public const MIN_TRIP_HOURS = 1;

    public const MAX_TRIP_HOURS = 12;

    /** A trip inside one country takes this long when nothing better is known. */
    private const LOCAL_TRIP_HOURS = 1;

    private readonly CarbonImmutable $now;

    public function __construct(
        private readonly PricingSetting $settings,
        ?CarbonInterface $now = null,
        private readonly PlaceCountry $places = new PlaceCountry,
    ) {
        $this->now = CarbonImmutable::instance($now ?? now())->second(0);
    }

    public static function make(?CarbonInterface $now = null): self
    {
        return new self(PricingSetting::current(), $now);
    }

    public function enabled(): bool
    {
        return (bool) ($this->settings->border_enabled ?? true) && $this->discountPercent() > 0;
    }

    public function discountPercent(): float
    {
        return max(0.0, min(100.0, (float) ($this->settings->border_discount_percent ?? 30)));
    }

    private function travelMinutes(): int
    {
        return (int) round(max(0.0, (float) ($this->settings->border_travel_hours ?? 2)) * 60);
    }

    /** The customer's time on the road: their estimate, else the set drive time. */
    private function tripMinutes(?int $tripHours): int
    {
        if ($tripHours === null) {
            return $this->travelMinutes();
        }

        return max(self::MIN_TRIP_HOURS, min(self::MAX_TRIP_HOURS, $tripHours)) * 60;
    }

    private function waitMinutes(): int
    {
        return max(0, (int) ($this->settings->border_wait_hours ?? 4)) * 60;
    }

    private function noticeHours(): int
    {
        return max(0, (int) ($this->settings->border_notice_hours ?? 2));
    }

    private function leadHours(): int
    {
        return max(0, (int) ($this->settings->lead_hours ?? 12));
    }

    /** Whether a search between these two countries can ever carry the offer. */
    public static function crossesHome(string $from, string $to): bool
    {
        return $from !== $to && ($from === self::HOME || $to === self::HOME);
    }

    /**
     * The 24 departure hours of one day for a trip from → to.
     *
     * `bookable` = far enough ahead to book online (discounted hours need
     * `border_notice_hours`, the rest the website's usual `lead_hours`);
     * `discount` = an empty car makes this hour cheaper; `cars` = how many.
     * `$tripHours` = the customer's time on the road (null = the set drive time).
     *
     * @return list<array{hour: int, time: string, bookable: bool, discount: bool, cars: int}>
     */
    public function hours(string $from, string $to, CarbonImmutable $day, ?int $tripHours = null): array
    {
        $day = $day->startOfDay();
        $runs = $this->enabled() && self::crossesHome($from, $to)
            ? $this->runs($from, $to, $day, $day->addDay(), $tripHours)
            : [];

        $discountFrom = $this->now->addHours($this->noticeHours());
        $normalFrom = $this->now->addHours($this->leadHours());

        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $slot = $day->setTime($h, 0);
            $cars = 0;
            foreach ($runs as $run) {
                if ($slot->gte($run['start']) && $slot->lte($run['end'])) {
                    $cars += $run['cars'];
                }
            }

            $discount = $cars > 0 && $slot->gte($discountFrom);

            $hours[] = [
                'hour' => $h,
                'time' => sprintf('%02d:00', $h),
                'bookable' => $discount || $slot->gte($normalFrom),
                'discount' => $discount,
                'cars' => $discount ? $cars : 0,
            ];
        }

        return $hours;
    }

    /**
     * The next discounted departures in this direction, soonest first: the
     * first bookable hour of each empty run.
     *
     * @return list<array{date: string, hour: int, time: string}>
     */
    public function upcoming(string $from, string $to, ?int $tripHours = null): array
    {
        if (! $this->enabled() || ! self::crossesHome($from, $to)) {
            return [];
        }

        $earliest = $this->now->addHours($this->noticeHours());
        $slots = [];

        foreach ($this->runs($from, $to, $this->now, $this->now->addDays(self::UPCOMING_DAYS), $tripHours) as $run) {
            $first = $this->ceilHour($run['start']->max($earliest));
            if ($first->gt($run['end']) || $first->gt($this->now->addDays(self::UPCOMING_DAYS))) {
                continue;
            }
            $slots[$first->format('Y-m-d H')] = $first;
        }

        ksort($slots);

        return array_values(array_map(
            static fn (CarbonImmutable $s): array => ['date' => $s->toDateString(), 'hour' => $s->hour, 'time' => $s->format('H:00')],
            array_slice($slots, 0, self::UPCOMING_LIMIT),
        ));
    }

    /**
     * Everything the website needs for one search.
     *
     * @return array<string, mixed>
     */
    public function payload(string $from, string $to, CarbonImmutable $day, ?int $tripHours = null): array
    {
        return [
            'enabled' => $this->enabled(),
            'discount_percent' => $this->trim($this->discountPercent()),
            'home' => self::HOME,
            'timezone' => config('app.timezone'),
            'today' => $this->now->toDateString(),
            'max_date' => $this->now->addDays(self::MAX_DAYS_AHEAD)->toDateString(),
            'whatsapp' => (string) ($this->settings->whatsapp ?? ''),
            'countries' => array_map(
                static fn (string $code): array => ['code' => $code, 'name_en' => self::COUNTRY_NAMES[$code]['en'], 'name_ar' => self::COUNTRY_NAMES[$code]['ar']],
                self::COUNTRIES,
            ),
            'query' => ['from' => $from, 'to' => $to, 'date' => $day->toDateString(), 'hours' => $this->trim($this->tripMinutes($tripHours) / 60)],
            'hours' => $this->hours($from, $to, $day, $tripHours),
            'upcoming' => $this->upcoming($from, $to, $tripHours),
        ];
    }

    /**
     * The empty runs from → to whose leaving window touches [$from, $until].
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, cars: int}>
     */
    public function runs(string $from, string $to, CarbonInterface $rangeStart, CarbonInterface $rangeEnd, ?int $tripHours = null): array
    {
        if (! self::crossesHome($from, $to) || ! Schema::hasTable('limo_legs')) {
            return [];
        }

        $rangeStart = CarbonImmutable::instance($rangeStart);
        $rangeEnd = CarbonImmutable::instance($rangeEnd);

        $runs = [];
        foreach ($this->allRuns($rangeStart->subDays(8), $rangeEnd->addDays(2), $this->tripMinutes($tripHours)) as $run) {
            if ($run['from'] === $from && $run['to'] === $to && $run['end']->gte($rangeStart) && $run['start']->lte($rangeEnd)) {
                $runs[] = ['start' => $run['start'], 'end' => $run['end'], 'cars' => $run['cars']];
            }
        }

        return $runs;
    }

    /**
     * $trip = the customer's minutes on the road: riding a car home they must
     * arrive before its next job, riding one out they must arrive before the
     * pick-up it is going for.
     *
     * @return list<array{from: string, to: string, start: CarbonImmutable, end: CarbonImmutable, cars: int}>
     */
    private function allRuns(CarbonImmutable $from, CarbonImmutable $to, int $trip): array
    {
        $wait = $this->waitMinutes();
        $legs = $this->legs($from, $to);

        // What each trip leaves behind and needs, before anything cancels.
        $supply = [];
        $demand = [];
        foreach ($legs as $id => $leg) {
            if ($leg['end_country'] !== self::HOME) {
                $supply[$id] = ['country' => $leg['end_country'], 'at' => $leg['end'], 'until' => null, 'cars' => $leg['cars']];
            }
            if ($leg['from_country'] !== self::HOME) {
                $demand[$id] = ['country' => $leg['from_country'], 'at' => $leg['start'], 'since' => null, 'cars' => $leg['cars']];
            }
        }

        // One assigned car: its trips follow each other, so a car left in a
        // country for that car's next trip there never drives back empty.
        $byCar = [];
        foreach ($legs as $id => $leg) {
            if ($leg['car_id'] !== null) {
                $byCar[$leg['car_id']][] = $id;
            }
        }
        foreach ($byCar as $ids) {
            for ($k = 0; $k + 1 < count($ids); $k++) {
                $a = $legs[$ids[$k]];
                $b = $legs[$ids[$k + 1]];

                if ($a['end_country'] === $b['from_country']) {
                    unset($supply[$ids[$k]], $demand[$ids[$k + 1]]);

                    continue;
                }

                // The car moves on to somewhere else: the customer riding it must
                // arrive in time for its next trip, and it cannot leave home before its last.
                if (isset($supply[$ids[$k]])) {
                    $supply[$ids[$k]]['until'] = $b['start']->subMinutes($trip);
                }
                if (isset($demand[$ids[$k + 1]]) && $a['end_country'] === self::HOME) {
                    $demand[$ids[$k + 1]]['since'] = $a['end'];
                }
            }
        }

        // Any car will do: one that is already in a country and needed there
        // again within the waiting time stays, so neither run happens.
        $supplyUnits = $this->units($supply);
        $demandUnits = $this->units($demand);
        usort($supplyUnits, static fn (array $x, array $y): int => $x['at'] <=> $y['at']);
        usort($demandUnits, static fn (array $x, array $y): int => $x['at'] <=> $y['at']);

        $usedSupply = [];
        $usedDemand = [];
        foreach ($demandUnits as $d => $need) {
            foreach ($supplyUnits as $s => $car) {
                if (isset($usedSupply[$s]) || $car['country'] !== $need['country']) {
                    continue;
                }
                if ($car['at']->lte($need['at']) && $car['at']->diffInMinutes($need['at']) <= $wait) {
                    $usedSupply[$s] = true;
                    $usedDemand[$d] = true;

                    break;
                }
            }
        }

        $runs = [];
        foreach ($supplyUnits as $s => $car) {
            if (isset($usedSupply[$s])) {
                continue;
            }
            $end = $car['at']->addMinutes($wait);
            if ($car['until'] !== null && $car['until']->lt($end)) {
                $end = $car['until'];
            }
            if ($end->gte($car['at'])) {
                $runs[] = ['from' => $car['country'], 'to' => self::HOME, 'start' => $car['at'], 'end' => $end, 'cars' => 1];
            }
        }
        foreach ($demandUnits as $d => $need) {
            if (isset($usedDemand[$d])) {
                continue;
            }
            $end = $need['at']->subMinutes($trip);
            $start = $end->subMinutes($wait);
            if ($need['since'] !== null && $need['since']->gt($start)) {
                $start = $need['since'];
            }
            if ($end->gte($start)) {
                $runs[] = ['from' => self::HOME, 'to' => $need['country'], 'start' => $start, 'end' => $end, 'cars' => 1];
            }
        }

        return $runs;
    }

    /**
     * One entry per car, so two cars on one trip are two runs.
     *
     * @template T of array{cars: int}
     *
     * @param  array<int, T>  $events
     * @return list<T>
     */
    private function units(array $events): array
    {
        $units = [];
        foreach ($events as $event) {
            for ($c = 0; $c < $event['cars']; $c++) {
                $units[] = $event;
            }
        }

        return $units;
    }

    /**
     * The booked, not-cancelled trips starting in the window, with where and
     * when each starts and ends.
     *
     * @return array<int, array{car_id: int|null, start: CarbonImmutable, end: CarbonImmutable, from_country: string, end_country: string, cars: int}>
     */
    private function legs(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $hasCountry = Schema::hasColumn('limo_legs', 'from_country');
        $columns = ['id', 'legable_id', 'service_type', 'car_id', 'from_location', 'to_location', 'start_at', 'hours', 'days', 'units'];
        if ($hasCountry) {
            array_push($columns, 'from_country', 'to_country');
        }

        $live = LimoBooking::query()
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', LimoBooking::STATUS_CANCELLED))
            ->select('id');

        $rows = LimoLeg::query()
            ->where('legable_type', (new LimoBooking)->getMorphClass())
            ->whereIn('legable_id', $live)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', LimoLeg::STATUS_CANCELLED))
            ->whereNotNull('start_at')
            ->whereBetween('start_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->orderBy('start_at')
            ->orderBy('id')
            ->get($columns);

        $travel = $this->travelMinutes();
        $legs = [];

        foreach ($rows as $leg) {
            if ($leg->start_at === null) {
                continue;
            }

            $start = CarbonImmutable::instance($leg->start_at);
            $fromCountry = $this->places->of($hasCountry ? $leg->from_country : null, $leg->from_location);

            if ($leg->service_type === LimoLeg::TYPE_CHAUFFEUR) {
                // A chauffeur job stays in its country for its hours (and days).
                $endCountry = $fromCountry;
                $minutes = (max(1, (int) $leg->days) - 1) * 24 * 60 + (int) round(max(1.0, (float) ($leg->hours ?? 1)) * 60);
            } else {
                $endCountry = $this->places->of($hasCountry ? $leg->to_country : null, $leg->to_location);
                $minutes = $endCountry !== $fromCountry ? $travel : self::LOCAL_TRIP_HOURS * 60;
            }

            $legs[(int) $leg->id] = [
                'car_id' => $leg->car_id !== null ? (int) $leg->car_id : null,
                'start' => $start,
                'end' => $start->addMinutes($minutes),
                'from_country' => $fromCountry,
                'end_country' => $endCountry,
                'cars' => max(1, (int) ($leg->units ?? 1)),
            ];
        }

        return $legs;
    }

    private function ceilHour(CarbonImmutable $time): CarbonImmutable
    {
        $hour = $time->startOfHour();

        return $hour->eq($time) ? $hour : $hour->addHour();
    }

    private function trim(float $value): float|int
    {
        return floor($value) === $value ? (int) $value : round($value, 2);
    }
}

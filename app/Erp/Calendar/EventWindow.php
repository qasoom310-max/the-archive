<?php

declare(strict_types=1);

namespace App\Erp\Calendar;

use Carbon\CarbonImmutable;

/**
 * A dated window that matters for selling: Ramadan, an Eid, a national day
 * in a country whose people visit, a wedding season the owner named, a
 * closure.
 *
 * The one behaviour that makes the calendar smart lives in {@see lastYear()}:
 * an Islamic window is shifted by a HIJRI year, not a Gregorian one, so Eid
 * last year is compared with Eid this year even though they are 11 days apart
 * on the wall calendar.
 */
final readonly class EventWindow
{
    public const KIND_ISLAMIC = 'islamic';

    public const KIND_NATIONAL = 'national';

    public const KIND_CUSTOM = 'custom';

    /** Days the business did not operate — these are "no data", never "no demand". */
    public const KIND_CLOSED = 'closed';

    /** Shared across the Gulf (the Islamic calendar), rather than one country's. */
    public const COUNTRY_GCC = 'GCC';

    public function __construct(
        public string $key,
        public string $label,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $kind,
        public string $country = self::COUNTRY_GCC,
        public bool $hijri = false,
        public ?int $customId = null,
    ) {}

    public function contains(CarbonImmutable $day): bool
    {
        return $day->greaterThanOrEqualTo($this->start->startOfDay())
            && $day->lessThanOrEqualTo($this->end->endOfDay());
    }

    public function overlaps(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $this->start->lessThanOrEqualTo($to->endOfDay())
            && $this->end->greaterThanOrEqualTo($from->startOfDay());
    }

    public function days(): int
    {
        return (int) $this->start->startOfDay()->diffInDays($this->end->startOfDay()) + 1;
    }

    /** The same window one year earlier — a Hijri year for Islamic dates. */
    public function lastYear(): self
    {
        if (! $this->hijri) {
            return new self(
                $this->key, $this->label,
                $this->start->subYear(), $this->end->subYear(),
                $this->kind, $this->country, false, $this->customId,
            );
        }

        $s = Hijri::fromGregorian($this->start);
        $e = Hijri::fromGregorian($this->end);

        return new self(
            $this->key, $this->label,
            Hijri::toGregorian($s['year'] - 1, $s['month'], min($s['day'], Hijri::daysInMonth($s['year'] - 1, $s['month']))),
            Hijri::toGregorian($e['year'] - 1, $e['month'], min($e['day'], Hijri::daysInMonth($e['year'] - 1, $e['month']))),
            $this->kind, $this->country, true, $this->customId,
        );
    }

    public function isClosed(): bool
    {
        return $this->kind === self::KIND_CLOSED;
    }
}

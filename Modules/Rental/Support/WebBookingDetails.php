<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Illuminate\Support\Carbon;

/**
 * Reads a website booking's own line details: what the customer filled in on
 * the product page, sent by the plugin as `item_meta`.
 *
 * The site's booking forms are not ours (the car-hire products use one set of
 * field names, the fare finder another, and both have changed over the years),
 * so this goes by what a label MEANS rather than by an exact key: anything that
 * says "pick up" and is not a date is where the trip starts, and so on. What it
 * cannot place is still shown to the desk, unchanged.
 */
final class WebBookingDetails
{
    /**
     * The details as label → value pairs, in the order the website listed them.
     *
     * Accepts the plugin's list of {label, value} and, for older deliveries, a
     * plain key → value object. Empty values and the plugin's own internal
     * keys (a leading underscore) are dropped.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    public static function lines(array $payload): array
    {
        $lines = [];

        foreach (['item_meta', 'item_meta_raw'] as $key) {
            $meta = $payload[$key] ?? null;
            if (! is_array($meta)) {
                continue;
            }

            foreach ($meta as $name => $entry) {
                if (is_array($entry)) {
                    $label = $entry['label'] ?? $entry['key'] ?? null;
                    $value = $entry['value'] ?? null;
                } else {
                    $label = is_string($name) ? $name : null;
                    $value = $entry;
                }

                if (! is_scalar($label) || ! is_scalar($value)) {
                    continue;
                }

                $label = trim(html_entity_decode(strip_tags((string) $label)));
                $value = trim(html_entity_decode(strip_tags((string) $value)));

                if ($label === '' || $value === '' || str_starts_with($label, '_')) {
                    continue;
                }

                $lines[] = ['label' => $label, 'value' => $value];
            }

            // The readable list is enough when the website sent one.
            if ($lines !== []) {
                break;
            }
        }

        return $lines;
    }

    /** @param  list<array{label: string, value: string}>  $lines */
    public static function pickupLocation(array $lines): ?string
    {
        return self::first($lines, static fn (string $l): bool => ! self::isWhen($l)
            && (self::has($l, ['pick', 'from', 'origin', 'start point', 'starting'])));
    }

    /** @param  list<array{label: string, value: string}>  $lines */
    public static function dropoffLocation(array $lines): ?string
    {
        return self::first($lines, static fn (string $l): bool => ! self::isWhen($l)
            && (self::has($l, ['drop', 'destination', 'return loc', 'return place', 'going to'])
                || in_array($l, ['to', 'to location', 'where to'], true)));
    }

    /** @param  list<array{label: string, value: string}>  $lines */
    public static function pickupAt(array $lines): ?Carbon
    {
        $start = static fn (string $l): bool => ! self::has($l, ['drop', 'return', 'end']);

        return self::moment($lines, $start);
    }

    /** @param  list<array{label: string, value: string}>  $lines */
    public static function dropoffAt(array $lines): ?Carbon
    {
        return self::moment($lines, static fn (string $l): bool => self::has($l, ['drop', 'return', 'end']));
    }

    /**
     * A date, joined to a separate time field when the form asked for them
     * apart ("Pickup date" + "Pickup time").
     *
     * @param  list<array{label: string, value: string}>  $lines
     * @param  callable(string): bool  $side
     */
    private static function moment(array $lines, callable $side): ?Carbon
    {
        $both = self::first($lines, static fn (string $l): bool => $side($l)
            && self::has($l, ['date']) && self::has($l, ['time']));
        if ($both !== null && ($at = self::parse($both)) !== null) {
            return $at;
        }

        $date = self::first($lines, static fn (string $l): bool => $side($l) && self::has($l, ['date', 'when']));
        $time = self::first($lines, static fn (string $l): bool => $side($l) && self::has($l, ['time']) && ! self::has($l, ['date']));

        if ($date === null) {
            return $time !== null ? self::parse($time) : null;
        }

        return self::parse(trim($date . ' ' . ($time ?? ''))) ?? self::parse($date);
    }

    /**
     * The site writes dates day-first ("24/09/2026 18:00"), sometimes with
     * " at " between, sometimes as "2026-09-24T18:00". Anything that will not
     * parse is left empty: an empty pick-up time is obvious to the desk, a
     * wrong one is not.
     */
    public static function parse(string $value): ?Carbon
    {
        $value = trim(preg_replace('/\s+/', ' ', str_ireplace([' at ', '@'], ' ', $value)) ?? '');
        if ($value === '') {
            return null;
        }

        foreach (['d/m/Y H:i', 'd/m/Y g:i A', 'd/m/Y g:i a', 'd/m/Y', 'd-m-Y H:i', 'd-m-Y',
            'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d g:i A', 'Y-m-d'] as $format) {
            try {
                $at = Carbon::createFromFormat('!' . $format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($at instanceof Carbon && $at->format($format) === $value) {
                return $at;
            }
        }

        // Free-form text only when it at least looks like a date: "3" (days)
        // or "Sedan" must never turn into a pick-up time.
        if (preg_match('/\d{1,4}[\/\-.]\d{1,2}|\d{1,2}\s+[A-Za-z]{3,}|[A-Za-z]{3,}\s+\d{1,2}/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{label: string, value: string}>  $lines
     * @param  callable(string): bool  $matches
     */
    private static function first(array $lines, callable $matches): ?string
    {
        foreach ($lines as $line) {
            if ($matches(self::normalise($line['label']))) {
                return $line['value'];
            }
        }

        return null;
    }

    private static function isWhen(string $label): bool
    {
        return self::has($label, ['date', 'time', 'when', 'day']);
    }

    /** @param  list<string>  $words */
    private static function has(string $label, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($label, $word)) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $label): string
    {
        $label = preg_replace('/[^a-z]+/', ' ', strtolower($label)) ?? '';

        return trim($label);
    }
}

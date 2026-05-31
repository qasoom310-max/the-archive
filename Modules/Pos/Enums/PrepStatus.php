<?php

declare(strict_types=1);

namespace Modules\Pos\Enums;

/**
 * Linear KDS lifecycle for one order line:
 *
 *   Pending  → cashier pushed it through, station hasn't started yet.
 *   Preparing → station tapped "Start" — clock is ticking on the cook.
 *   Ready    → station tapped "Ready" — runner can pick it up.
 *   Completed → runner / cashier dismissed it. Disappears from the KDS.
 *
 * A line whose category has no station never gets a status (column
 * stays `null`); the KDS query filters those out by joining on station.
 */
enum PrepStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('New'),
            self::Preparing => __('Preparing'),
            self::Ready => __('Ready'),
            self::Completed => __('Done'),
        };
    }

    /**
     * Single-tap forward transition used by the KDS card's primary button.
     * Returns null at the terminal state — the UI hides the button there.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Pending => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Completed,
            self::Completed => null,
        };
    }

    public function nextLabel(): ?string
    {
        return match ($this) {
            self::Pending => __('Start preparing'),
            self::Preparing => __('Mark ready'),
            self::Ready => __('Complete'),
            self::Completed => null,
        };
    }

    /**
     * Tailwind colour token used by the column header + card stripe.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Preparing => 'sky',
            self::Ready => 'emerald',
            self::Completed => 'chrome',
        };
    }

    /**
     * Lines in these statuses are still on the screen. Completed disappears.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Preparing, self::Ready];
    }
}

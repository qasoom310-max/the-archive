<?php

declare(strict_types=1);

namespace App\Erp\Views;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Odoo 19 "rotting / aging record" rule: a card whose tracked timestamp is
 * older than {@see $days} gets a subtle visual degradation cue.
 */
final readonly class RottingRule
{
    public function __construct(
        public string $field,
        public int $days,
    ) {}

    public function isRotting(Model $record): bool
    {
        $value = $record->getAttribute($this->field);

        if (! $value instanceof Carbon) {
            return false;
        }

        return $value->lt(Carbon::now()->subDays($this->days));
    }

    /** 0 = fresh; grows the longer the record has been untouched. */
    public function staleDays(Model $record): int
    {
        $value = $record->getAttribute($this->field);

        if (! $value instanceof Carbon) {
            return 0;
        }

        return max(0, (int) $value->diffInDays(Carbon::now()));
    }
}

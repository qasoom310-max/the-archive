<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A percentage off one service, optionally windowed.
 *
 * @property int $id
 * @property string $service_id
 * @property bool $active
 * @property float $percent
 * @property string|null $label_en
 * @property string|null $label_ar
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
final class PricingOffer extends Model
{
    protected $table = 'pricing_offers';

    /** @var list<string> */
    protected $fillable = [
        'service_id', 'active', 'percent', 'label_en', 'label_ar', 'starts_at', 'ends_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => false, 'percent' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'percent' => 'float',
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    /**
     * Live right now — the flag AND the window.
     *
     * Expiry and scheduling are decided here and published as a plain boolean,
     * so the website never runs date logic against a visitor's browser clock.
     * An offer that has ended, or has not started, goes out as inactive
     * whatever its flag says.
     */
    public function isLive(?CarbonImmutable $on = null): bool
    {
        if (! $this->active) {
            return false;
        }

        $day = ($on ?? CarbonImmutable::now())->startOfDay();

        if ($this->starts_at !== null && $day->lessThan(CarbonImmutable::instance($this->starts_at)->startOfDay())) {
            return false;
        }

        if ($this->ends_at !== null && $day->greaterThan(CarbonImmutable::instance($this->ends_at)->startOfDay())) {
            return false;
        }

        return true;
    }
}

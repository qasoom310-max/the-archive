<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * Worth publishing right now — the flag, and not yet fully expired.
     *
     * `starts_at`/`ends_at` describe the TRAVEL window the discount applies
     * to (e.g. "book any trip between 16 and 24 Sept"), not a window on
     * when the offer may be BOOKED — a customer must be able to book that
     * trip today, ahead of the 16th. So a not-yet-started offer is still
     * live (its percent/cars publish in full the moment an admin switches
     * it on); only OFF or already-ENDED zeroes it. The website is the one
     * that knows which date the visitor is asking about, so it compares
     * the visitor's chosen travel date against the published `starts`/
     * `ends` before applying `percent` to that particular quote — this
     * flag only guards against a stale or forgotten-off promo publishing
     * forever.
     */
    public function isLive(?CarbonImmutable $on = null): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->ends_at === null) {
            return true;
        }

        $day = ($on ?? CarbonImmutable::now())->startOfDay();

        return ! $day->greaterThan(CarbonImmutable::instance($this->ends_at)->startOfDay());
    }

    /**
     * Which of the service's cars this offer applies to. Empty means "not
     * configured yet" — the admin screen and the published payload both fall
     * back to every car the service offers, not to nothing.
     *
     * @return BelongsToMany<PricingCar, $this>
     */
    public function cars(): BelongsToMany
    {
        return $this->belongsToMany(PricingCar::class, 'pricing_offer_cars', 'offer_id', 'car_id');
    }
}

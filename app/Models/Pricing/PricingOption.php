<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One answer to a service's question — a zone, an hour block, a destination.
 *
 * @property int $id
 * @property string $service_id
 * @property string $code
 * @property string $label_en
 * @property string $label_ar
 * @property string|null $short_en
 * @property string|null $short_ar
 * @property int|null $hours
 * @property int $sort
 * @property bool $active
 */
final class PricingOption extends Model
{
    protected $table = 'pricing_options';

    /** @var list<string> */
    protected $fillable = [
        'service_id', 'code', 'label_en', 'label_ar',
        'short_en', 'short_ar', 'hours', 'sort', 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['hours' => 'integer', 'sort' => 'integer', 'active' => 'boolean'];
    }

    /**
     * @return BelongsTo<PricingService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(PricingService::class, 'service_id');
    }

    /**
     * @return HasMany<PricingRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(PricingRate::class, 'option_id');
    }
}

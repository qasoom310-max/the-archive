<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Something the customer can book — an airport transfer, an hourly chauffeur.
 * Inactive means the website hides it entirely, fares and all.
 *
 * @property string $id
 * @property string $name_en
 * @property string $name_ar
 * @property string $ask_en
 * @property string $ask_ar
 * @property string|null $note_en
 * @property string|null $note_ar
 * @property float|null $return_factor
 * @property int $sort
 * @property bool $active
 */
final class PricingService extends Model
{
    protected $table = 'pricing_services';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'name_en', 'name_ar', 'ask_en', 'ask_ar',
        'note_en', 'note_ar', 'return_factor', 'sort', 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['return_factor' => 'float', 'sort' => 'integer', 'active' => 'boolean'];
    }

    /**
     * @return HasMany<PricingOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(PricingOption::class, 'service_id')->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<PricingExtraHour, $this>
     */
    public function extraHours(): HasMany
    {
        return $this->hasMany(PricingExtraHour::class, 'service_id');
    }

    /**
     * @return HasOne<PricingOffer, $this>
     */
    public function offer(): HasOne
    {
        return $this->hasOne(PricingOffer::class, 'service_id');
    }
}

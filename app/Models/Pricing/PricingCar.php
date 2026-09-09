<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use Illuminate\Database\Eloquent\Model;

/**
 * A car class the customer picks between. The id (`sedan`, `luxury`) is
 * quoted back by the website, so it is stable and never renumbered.
 *
 * @property string $id
 * @property string $name_en
 * @property string $name_ar
 * @property string $model
 * @property int $pax
 * @property int $bags
 * @property int $sort
 * @property bool $active
 */
final class PricingCar extends Model
{
    protected $table = 'pricing_cars';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['id', 'name_en', 'name_ar', 'model', 'pax', 'bags', 'sort', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['pax' => 'integer', 'bags' => 'integer', 'sort' => 'integer', 'active' => 'boolean'];
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use Illuminate\Database\Eloquent\Model;

/**
 * The fare for one (option, car) pair. Its absence is meaningful: the website
 * hides that car for that option rather than showing a zero.
 *
 * @property int $id
 * @property int $option_id
 * @property string $car_id
 * @property float $amount
 */
final class PricingRate extends Model
{
    protected $table = 'pricing_rates';

    /** @var list<string> */
    protected $fillable = ['option_id', 'car_id', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['option_id' => 'integer', 'amount' => 'float'];
    }
}

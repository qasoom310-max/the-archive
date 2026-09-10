<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use Illuminate\Database\Eloquent\Model;

/**
 * Charged per hour past a booked block.
 *
 * @property int $id
 * @property string $service_id
 * @property string $car_id
 * @property float $amount
 */
final class PricingExtraHour extends Model
{
    protected $table = 'pricing_extra_hours';

    /** @var list<string> */
    protected $fillable = ['service_id', 'car_id', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'float'];
    }
}

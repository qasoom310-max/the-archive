<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One priced line on a {@see LimoQuotation}: a vehicle over a window (hours ×
 * units) at a rate, with a discount and VAT giving a net. A quotation can carry
 * unlimited lines; the quotation's grand total is the sum of the line nets.
 *
 * @property int $id
 * @property int $quotation_id
 * @property int $sequence
 * @property string|null $quote_type
 * @property string|null $rate_type
 * @property Carbon|null $date_from
 * @property Carbon|null $date_to
 * @property float|null $hours
 * @property int $units
 * @property string|null $vehicle
 * @property string|null $vehicle_details
 * @property float $rate
 * @property float $discount
 * @property float $vat
 * @property float $line_total
 * @property float $net_amount
 */
final class LimoQuotationLine extends Model
{
    protected $table = 'limo_quotation_lines';

    /** @var list<string> */
    protected $fillable = [
        'quotation_id', 'sequence', 'quote_type', 'rate_type', 'date_from', 'date_to',
        'hours', 'units', 'vehicle', 'vehicle_details', 'rate', 'discount', 'vat',
        'line_total', 'net_amount',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'units' => 1,
        'rate' => 0,
        'discount' => 0,
        'vat' => 0,
        'line_total' => 0,
        'net_amount' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quotation_id' => 'integer',
            'sequence' => 'integer',
            'date_from' => 'datetime',
            'date_to' => 'datetime',
            'hours' => 'float',
            'units' => 'integer',
            'rate' => 'float',
            'discount' => 'float',
            'vat' => 'float',
            'line_total' => 'float',
            'net_amount' => 'float',
        ];
    }

    /**
     * @return BelongsTo<LimoQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(LimoQuotation::class, 'quotation_id');
    }

    /** Gross line total = rate × units (units is the quantity multiplier). */
    public static function grossFor(float $rate, int $units): float
    {
        return round($rate * max(1, $units), 3);
    }

    /** Net line = gross − discount + VAT (never below zero before VAT). */
    public static function netFor(float $rate, int $units, float $discount, float $vat): float
    {
        return round(max(0.0, self::grossFor($rate, $units) - $discount) + $vat, 3);
    }
}

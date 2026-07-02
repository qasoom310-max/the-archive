<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One leg of a limousine trip, shared by bookings and quotations. A leg is a
 * transfer (one-way From → To at a time) or a chauffeur/disposal (a car for
 * `hours` per day across `days` consecutive days). Priced by rate × basis, less
 * discount, plus VAT.
 *
 * @property int $id
 * @property string $legable_type
 * @property int $legable_id
 * @property int $sequence
 * @property string $service_type
 * @property int|null $car_id
 * @property string|null $from_location
 * @property string|null $to_location
 * @property Carbon|null $start_at
 * @property float|null $hours
 * @property int $days
 * @property string|null $vehicle
 * @property string|null $vehicle_details
 * @property float $rate
 * @property string $rate_basis
 * @property float $discount
 * @property float $vat
 * @property float $line_total
 * @property float $net_amount
 * @property string|null $notes
 */
final class LimoLeg extends Model
{
    protected $table = 'limo_legs';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_CHAUFFEUR = 'chauffeur';

    public const BASIS_TRIP = 'trip';

    public const BASIS_HOUR = 'hour';

    public const BASIS_DAY = 'day';

    /** @var list<string> */
    protected $fillable = [
        'legable_type', 'legable_id', 'sequence', 'service_type', 'car_id', 'from_location',
        'to_location', 'start_at', 'hours', 'days', 'vehicle', 'vehicle_details',
        'rate', 'rate_basis', 'discount', 'vat', 'line_total', 'net_amount', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'service_type' => self::TYPE_TRANSFER,
        'days' => 1,
        'rate' => 0,
        'rate_basis' => self::BASIS_TRIP,
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
            'legable_id' => 'integer',
            'sequence' => 'integer',
            'car_id' => 'integer',
            'start_at' => 'datetime',
            'hours' => 'float',
            'days' => 'integer',
            'rate' => 'float',
            'discount' => 'float',
            'vat' => 'float',
            'line_total' => 'float',
            'net_amount' => 'float',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function legable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Gross leg amount before discount/VAT:
     *   - per hour → rate × hours × days
     *   - per day  → rate × days
     *   - per trip → rate (flat)
     */
    public static function grossFor(string $basis, float $rate, ?float $hours, int $days): float
    {
        $days = max(1, $days);
        $gross = match ($basis) {
            self::BASIS_HOUR => $rate * (float) ($hours ?? 0) * $days,
            self::BASIS_DAY => $rate * $days,
            default => $rate,
        };

        return round($gross, 3);
    }

    /** Net leg = gross − discount + VAT (never below zero before VAT). */
    public static function netFor(string $basis, float $rate, ?float $hours, int $days, float $discount, float $vat): float
    {
        return round(max(0.0, self::grossFor($basis, $rate, $hours, $days) - $discount) + $vat, 3);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function serviceTypeOptions(): array
    {
        return [
            ['value' => self::TYPE_TRANSFER, 'label' => 'Pick & drop (transfer)'],
            ['value' => self::TYPE_CHAUFFEUR, 'label' => 'Chauffeur (hours)'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function rateBasisOptions(): array
    {
        return [
            ['value' => self::BASIS_TRIP, 'label' => 'Per trip'],
            ['value' => self::BASIS_HOUR, 'label' => 'Per hour'],
            ['value' => self::BASIS_DAY, 'label' => 'Per day'],
        ];
    }
}

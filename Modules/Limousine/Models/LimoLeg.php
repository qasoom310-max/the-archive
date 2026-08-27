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
 * @property string|null $reference  Plain running number, e.g. "10000"
 * @property string|null $status     queue|confirmed|active|completed|cancelled; null on quotation legs
 * @property string $service_type
 * @property int|null $car_id
 * @property int|null $driver_id  Logical ref to the shared rental_drivers table
 * @property string|null $driver  Driver name at assignment time
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

    /**
     * Each leg is dispatched on its own, so it carries its own status —
     * mirroring the booking's, which now only summarises its legs.
     */
    public const STATUS_QUEUE = 'queue';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /** First reference handed out. Kept in step with the backfill migration. */
    public const REFERENCE_START = 10000;

    /** @var list<string> */
    protected $fillable = [
        'legable_type', 'legable_id', 'sequence', 'reference', 'status', 'service_type',
        'car_id', 'driver_id', 'driver', 'from_location', 'to_location', 'start_at', 'hours', 'days', 'vehicle',
        'vehicle_details', 'rate', 'rate_basis', 'discount', 'vat', 'line_total', 'net_amount', 'notes',
    ];

    protected static function booted(): void
    {
        // Derive the running number from the row's own id rather than
        // `max(reference) + 1`: the id is already unique and monotonic, so two
        // legs created at the same moment cannot collide, and a deleted leg
        // leaves a gap instead of handing its number to someone else. The
        // offset keeps it clear of the backfilled range — see the migration.
        static::created(static function (self $leg): void {
            if ($leg->reference === null || $leg->reference === '') {
                $leg->reference = (string) (self::REFERENCE_START - 1 + (int) $leg->getKey());
                $leg->saveQuietly();
            }
        });
    }

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
            'driver_id' => 'integer',
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

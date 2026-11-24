<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A driver's own record of one trip, from his trip link: dashboard photo, KM,
 * fuel and location at the start and at the end. Fuel is in eighths of a tank
 * (0 = empty, 8 = full). `flags` lists what the office should look at.
 *
 * @property int $id
 * @property int $leg_id
 * @property int $driver_id
 * @property int|null $car_id
 * @property Carbon|null $started_at
 * @property string|null $start_photo
 * @property array<string, mixed>|null $start_read
 * @property int|null $start_km
 * @property int|null $start_fuel
 * @property float|null $start_lat
 * @property float|null $start_lng
 * @property int|null $start_accuracy
 * @property Carbon|null $ended_at
 * @property string|null $end_photo
 * @property array<string, mixed>|null $end_read
 * @property int|null $end_km
 * @property int|null $end_fuel
 * @property float|null $end_lat
 * @property float|null $end_lng
 * @property int|null $end_accuracy
 * @property list<string>|null $flags
 * @property Carbon|null $customer_notified_at
 * @property-read LimoLeg|null $leg
 */
final class LimoTripRun extends Model
{
    public const DISK = 'local';

    public const FOLDER = 'limo_trip_photos';

    protected $table = 'limo_trip_runs';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leg_id' => 'integer', 'driver_id' => 'integer', 'car_id' => 'integer',
            'started_at' => 'datetime', 'ended_at' => 'datetime', 'customer_notified_at' => 'datetime',
            'start_read' => 'array', 'end_read' => 'array', 'flags' => 'array',
            'start_km' => 'integer', 'end_km' => 'integer', 'start_fuel' => 'integer', 'end_fuel' => 'integer',
            'start_lat' => 'float', 'start_lng' => 'float', 'end_lat' => 'float', 'end_lng' => 'float',
            'start_accuracy' => 'integer', 'end_accuracy' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LimoLeg, $this>
     */
    public function leg(): BelongsTo
    {
        return $this->belongsTo(LimoLeg::class, 'leg_id');
    }

    public function isStarted(): bool
    {
        return $this->started_at !== null;
    }

    public function isEnded(): bool
    {
        return $this->ended_at !== null;
    }

    /** Started and not ended: the trip the driver is on now. */
    public function isRunning(): bool
    {
        return $this->isStarted() && ! $this->isEnded();
    }

    public function distance(): ?int
    {
        return $this->start_km !== null && $this->end_km !== null ? $this->end_km - $this->start_km : null;
    }

    /** "1/2", "Full"… for a tank in eighths. */
    public static function fuelLabel(?int $eighths): string
    {
        if ($eighths === null) {
            return '—';
        }

        return ['Empty', '1/8', '1/4', '3/8', '1/2', '5/8', '3/4', '7/8', 'Full'][max(0, min(8, $eighths))];
    }

    /** Google Maps link for a recorded point, or null. */
    public static function mapUrl(?float $lat, ?float $lng): ?string
    {
        return $lat !== null && $lng !== null ? 'https://www.google.com/maps?q=' . $lat . ',' . $lng : null;
    }
}

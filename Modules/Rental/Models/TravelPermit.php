<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use App\Models\Concerns\HasReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One travel authorization: a named driver may take one company car to
 * another Gulf country between two dates. The car and driver details are a
 * snapshot taken when it was issued.
 *
 * @property int $id
 * @property string|null $reference
 * @property int $vehicle_id
 * @property int $driver_id
 * @property Carbon $issued_on
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property string $period
 * @property bool $shortened
 * @property string $plate_no
 * @property string $make
 * @property string $model
 * @property int $year
 * @property string $color
 * @property string $driver_name
 * @property string $driver_cpr
 * @property string $owner_name
 * @property string $owner_no
 * @property string|null $created_by
 */
final class TravelPermit extends Model
{
    use HasReference;

    public const ACTIVE = 'active';

    public const UPCOMING = 'upcoming';

    public const EXPIRED = 'expired';

    protected $table = 'rental_travel_permits';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'vehicle_id', 'driver_id', 'issued_on', 'valid_from', 'valid_to', 'period', 'shortened',
        'plate_no', 'make', 'model', 'year', 'color', 'driver_name', 'driver_cpr', 'owner_name', 'owner_no', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_id' => 'integer', 'driver_id' => 'integer', 'year' => 'integer', 'shortened' => 'boolean',
            'issued_on' => 'date', 'valid_from' => 'date', 'valid_to' => 'date',
        ];
    }

    public function referencePrefix(): string
    {
        return 'TA';
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /** Whether the letter is in force today, not yet, or over. */
    public function standing(): string
    {
        $today = Carbon::today();

        return match (true) {
            $today->lt($this->valid_from) => self::UPCOMING,
            $today->gt($this->valid_to) => self::EXPIRED,
            default => self::ACTIVE,
        };
    }

    /**
     * The letter this driver already holds for this car that still covers
     * today or a later day, newest end date first.
     */
    public static function inForce(int $vehicleId, int $driverId): ?self
    {
        return self::query()
            ->where('vehicle_id', $vehicleId)
            ->where('driver_id', $driverId)
            ->whereDate('valid_to', '>=', Carbon::today())
            ->orderByDesc('valid_to')
            ->first();
    }
}

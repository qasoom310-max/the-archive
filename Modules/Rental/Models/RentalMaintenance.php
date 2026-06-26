<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A vehicle maintenance record. While in progress the vehicle reads as under
 * maintenance; marking it done frees the vehicle again.
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $vehicle_id
 * @property Carbon|null $date
 * @property string $type
 * @property string|null $description
 * @property float $cost
 * @property int|null $odometer
 * @property string $status
 * @property string|null $notes
 * @property-read Vehicle|null $vehicle
 */
final class RentalMaintenance extends Model implements DefinesIrModel
{
    protected $table = 'rental_maintenance';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'vehicle_id', 'date', 'type', 'description',
        'cost', 'odometer', 'status', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'service',
        'cost' => 0,
        'status' => self::STATUS_SCHEDULED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_id' => 'integer',
            'date' => 'date',
            'cost' => 'float',
            'odometer' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (RentalMaintenance $record): void {
            if ($record->reference === null || $record->reference === '') {
                $record->reference = 'MNT/' . str_pad((string) $record->id, 5, '0', STR_PAD_LEFT);
                $record->saveQuietly();
            }
        });
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * Reflect this record's status onto its vehicle: in progress → maintenance,
     * done → available. Scheduled leaves the vehicle as-is.
     */
    public function syncVehicleStatus(): void
    {
        if ($this->vehicle_id === null) {
            return;
        }

        $vehicleStatus = match ($this->status) {
            self::STATUS_IN_PROGRESS => Vehicle::STATUS_MAINTENANCE,
            self::STATUS_DONE => Vehicle::STATUS_AVAILABLE,
            default => null,
        };

        if ($vehicleStatus !== null) {
            Vehicle::query()->whereKey($this->vehicle_id)->update(['status' => $vehicleStatus]);
        }
    }

    /**
     * The car is free to take off the road — available at the branch, or already
     * under maintenance (held by this very record). A rented / reserved car is
     * NOT free: it must be released (e.g. a replacement) first.
     */
    public function vehicleIsFree(): bool
    {
        $vehicle = $this->vehicle;

        return $vehicle !== null
            && in_array($vehicle->status, [Vehicle::STATUS_AVAILABLE, Vehicle::STATUS_MAINTENANCE], true);
    }

    /**
     * Workflow — Scheduled → Start. Takes the car off the road. Refuses if the
     * record isn't scheduled or the car isn't free; returns whether it moved.
     */
    public function start(): bool
    {
        if ($this->status !== self::STATUS_SCHEDULED || ! $this->vehicleIsFree()) {
            return false;
        }

        $this->status = self::STATUS_IN_PROGRESS;
        $this->save();

        if ($this->vehicle_id !== null) {
            Vehicle::query()->whereKey($this->vehicle_id)->update(['status' => Vehicle::STATUS_MAINTENANCE]);
        }

        return true;
    }

    /** Workflow — In progress → Done. Frees the car back to Available. */
    public function complete(): bool
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            return false;
        }

        $this->status = self::STATUS_DONE;
        $this->save();

        if ($this->vehicle_id !== null) {
            Vehicle::query()->whereKey($this->vehicle_id)->update(['status' => Vehicle::STATUS_AVAILABLE]);
        }

        return true;
    }

    /** Workflow — Scheduled → Cancelled (a plan that won't happen). */
    public function cancelRecord(): bool
    {
        if ($this->status !== self::STATUS_SCHEDULED) {
            return false;
        }

        $this->status = self::STATUS_CANCELLED;
        $this->save();

        return true;
    }

    /** Human label for the current status. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_DONE => 'Done',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Scheduled',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return [
            ['value' => 'service', 'label' => 'Service'],
            ['value' => 'repair', 'label' => 'Repair'],
            ['value' => 'oil_change', 'label' => 'Oil change'],
            ['value' => 'tyres', 'label' => 'Tyres'],
            ['value' => 'insurance', 'label' => 'Insurance'],
            ['value' => 'registration', 'label' => 'Registration'],
            ['value' => 'accident', 'label' => 'Accident'],
            ['value' => 'other', 'label' => 'Other'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_SCHEDULED, 'label' => 'Scheduled'],
            ['value' => self::STATUS_IN_PROGRESS, 'label' => 'In progress'],
            ['value' => self::STATUS_DONE, 'label' => 'Done'],
            ['value' => self::STATUS_CANCELLED, 'label' => 'Cancelled'],
        ];
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'rental.maintenance',
            name: 'Maintenance',
            class: self::class,
            table: 'rental_maintenance',
            module: 'rental',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('vehicle_id', 'Vehicle', 'many2one', relation: 'rental.vehicle', sequence: 20),
                new FieldDefinition('date', 'Date', 'date', sequence: 30),
                new FieldDefinition('type', 'Type', 'selection', selection: self::typeOptions(), sequence: 40),
                new FieldDefinition('cost', 'Cost', 'float', sequence: 50),
                new FieldDefinition('status', 'Status', 'selection', selection: self::statusOptions(), sequence: 60),
            ],
            views: [
                new ViewDefinition('Maintenance', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
                        ['field' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true],
                        ['field' => 'cost', 'label' => 'Cost', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['reference', 'description'],
                    'open' => '/app/rental/maintenance/{id}',
                ]),
            ],
        );
    }
}

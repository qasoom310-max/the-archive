<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A vehicle maintenance record. While in progress the vehicle reads as under
 * maintenance; marking it done frees the vehicle again.
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $requested_by_user_id
 * @property int|null $vehicle_id
 * @property Carbon|null $date
 * @property string $type
 * @property string|null $description
 * @property float $cost
 * @property int|null $odometer
 * @property string $priority
 * @property string $status
 * @property int|null $approved_by_user_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $notes
 * @property-read Vehicle|null $vehicle
 * @property-read User|null $approvedBy
 * @property-read User|null $requestedBy
 */
final class RentalMaintenance extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'rental_maintenance';

    // Work-order lifecycle: raised → a manager approves (or declines) → started
    // → completed. Modelled on fleet-maintenance practice (Fleetio / Oxmaint).
    public const STATUS_PENDING = 'pending';        // awaiting manager approval

    public const STATUS_APPROVED = 'approved';      // authorised, ready to start

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'requested_by_user_id', 'vehicle_id', 'date', 'type', 'priority', 'description',
        'cost', 'odometer', 'status', 'approved_by_user_id', 'approved_at',
        'started_at', 'completed_at', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'service',
        'priority' => self::PRIORITY_NORMAL,
        'cost' => 0,
        'status' => self::STATUS_PENDING,
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
            'requested_by_user_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function referencePrefix(): string
    {
        return 'MNT';
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * Reflect this record's status onto its vehicle: in progress → maintenance,
     * done → available. Anything else leaves the vehicle as-is.
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

    /** Workflow — Pending → Approved. A manager authorises the work / spend. */
    public function approve(User $by): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->approved_by_user_id = (int) $by->getKey();
        $this->approved_at = Carbon::now();
        $this->save();

        return true;
    }

    /** Workflow — Pending → Declined. A manager refuses the work. */
    public function decline(User $by): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $this->status = self::STATUS_DECLINED;
        $this->approved_by_user_id = (int) $by->getKey();
        $this->approved_at = Carbon::now();
        $this->save();

        return true;
    }

    /**
     * Workflow — Approved → In progress. Takes the car off the road. Refuses
     * unless the record is approved AND the car is free at the branch.
     */
    public function start(): bool
    {
        if ($this->status !== self::STATUS_APPROVED || ! $this->vehicleIsFree()) {
            return false;
        }

        $this->status = self::STATUS_IN_PROGRESS;
        $this->started_at = Carbon::now();
        $this->save();

        if ($this->vehicle_id !== null) {
            Vehicle::query()->whereKey($this->vehicle_id)->update(['status' => Vehicle::STATUS_MAINTENANCE]);
        }

        return true;
    }

    /**
     * Workflow — In progress → Done. Frees the car back to Available and captures
     * the service KM reading onto the vehicle's odometer (never decreasing).
     */
    public function complete(): bool
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            return false;
        }

        $this->status = self::STATUS_DONE;
        $this->completed_at = Carbon::now();
        $this->save();

        if ($this->vehicle_id !== null) {
            $attrs = ['status' => Vehicle::STATUS_AVAILABLE];

            if ($this->odometer !== null) {
                $current = Vehicle::query()->whereKey($this->vehicle_id)->value('odometer');
                $currentKm = is_numeric($current) ? (int) $current : null;
                if ($currentKm === null || $this->odometer > $currentKm) {
                    $attrs['odometer'] = $this->odometer;
                }
            }

            Vehicle::query()->whereKey($this->vehicle_id)->update($attrs);
        }

        return true;
    }

    /** Workflow — Pending / Approved → Cancelled (won't happen after all). */
    public function cancelRecord(): bool
    {
        if (! in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true)) {
            return false;
        }

        $this->status = self::STATUS_CANCELLED;
        $this->save();

        return true;
    }

    /** Open work orders (raised or authorised) the manager queue cares about. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_IN_PROGRESS], true);
    }

    /** Human label for the current status. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_DONE => 'Done',
            self::STATUS_DECLINED => 'Declined',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Pending approval',
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
            ['value' => self::STATUS_PENDING, 'label' => 'Pending approval'],
            ['value' => self::STATUS_APPROVED, 'label' => 'Approved'],
            ['value' => self::STATUS_IN_PROGRESS, 'label' => 'In progress'],
            ['value' => self::STATUS_DONE, 'label' => 'Done'],
            ['value' => self::STATUS_DECLINED, 'label' => 'Declined'],
            ['value' => self::STATUS_CANCELLED, 'label' => 'Cancelled'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function priorityOptions(): array
    {
        return [
            ['value' => self::PRIORITY_LOW, 'label' => 'Low'],
            ['value' => self::PRIORITY_NORMAL, 'label' => 'Normal'],
            ['value' => self::PRIORITY_HIGH, 'label' => 'High'],
            ['value' => self::PRIORITY_CRITICAL, 'label' => 'Critical'],
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

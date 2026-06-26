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
 * A car replacement on a live rental: the customer's car is swapped for another
 * mid-agreement. Activating moves the replacement car onto the order (a permanent
 * swap for the rest of the rental) and sends the original to maintenance (for a
 * breakdown / accident / service) or back to the fleet (a customer request).
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $order_id
 * @property int|null $customer_id
 * @property int|null $original_vehicle_id
 * @property int|null $replacement_vehicle_id
 * @property Carbon|null $date
 * @property string|null $reason
 * @property string|null $reason_type
 * @property int|null $original_return_km
 * @property string|null $original_return_fuel
 * @property string|null $original_condition_notes
 * @property int|null $replacement_handover_km
 * @property string|null $replacement_handover_fuel
 * @property string|null $replacement_condition_notes
 * @property int|null $created_by_user_id
 * @property string $status
 * @property string|null $notes
 * @property-read RentalCustomer|null $customer
 * @property-read RentalOrder|null $order
 * @property-read Vehicle|null $originalVehicle
 * @property-read Vehicle|null $replacementVehicle
 * @property-read User|null $createdBy
 */
final class RentalReplacement extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'rental_replacements';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    // Why the car is being swapped — drives where the original car goes.
    public const REASON_BREAKDOWN = 'breakdown';

    public const REASON_ACCIDENT = 'accident';

    public const REASON_SERVICE = 'service';

    public const REASON_CUSTOMER = 'customer_request';

    public const REASON_UPGRADE = 'upgrade';

    /** Swapping back to the car the customer first rented (the loaner is returned). */
    public const REASON_RETURN = 'return_original';

    /** Reasons that send the original car into maintenance rather than back to the fleet. */
    private const MAINTENANCE_REASONS = [self::REASON_BREAKDOWN, self::REASON_ACCIDENT, self::REASON_SERVICE];

    /** @var list<string> */
    protected $fillable = [
        'reference', 'order_id', 'customer_id', 'original_vehicle_id',
        'replacement_vehicle_id', 'date', 'reason', 'reason_type', 'status', 'notes',
        'original_return_km', 'original_return_fuel', 'original_condition_notes',
        'replacement_handover_km', 'replacement_handover_fuel', 'replacement_condition_notes',
        'created_by_user_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_ACTIVE];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'customer_id' => 'integer',
            'original_vehicle_id' => 'integer',
            'replacement_vehicle_id' => 'integer',
            'original_return_km' => 'integer',
            'replacement_handover_km' => 'integer',
            'created_by_user_id' => 'integer',
            'date' => 'date',
        ];
    }

    public function referencePrefix(): string
    {
        return 'REP';
    }

    /**
     * @return BelongsTo<RentalCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(RentalCustomer::class, 'customer_id');
    }

    /**
     * @return BelongsTo<RentalOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'order_id');
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function originalVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'original_vehicle_id');
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function replacementVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'replacement_vehicle_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** A mechanical reason (breakdown / accident / service) sends the original to maintenance. */
    public function originalGoesToMaintenance(): bool
    {
        return in_array($this->reason_type, self::MAINTENANCE_REASONS, true);
    }

    /**
     * Perform the swap: put the replacement car on the road and move the original
     * off it, then re-point the live order to the replacement car so the rest of
     * the agreement (and its final return) runs against the new vehicle. The
     * price stays as originally agreed — a like-for-like swap.
     */
    public function activate(): void
    {
        // Replacement car goes out on the road.
        if ($this->replacement_vehicle_id !== null) {
            $replacement = Vehicle::query()->find($this->replacement_vehicle_id);
            if ($replacement !== null) {
                $replacement->status = Vehicle::STATUS_RENTED;
                if ($this->replacement_handover_km !== null && $this->replacement_handover_km > (int) $replacement->odometer) {
                    $replacement->odometer = $this->replacement_handover_km;
                }
                $replacement->save();
            }
        }

        // Original car comes in — to maintenance or back to the fleet.
        if ($this->original_vehicle_id !== null) {
            $original = Vehicle::query()->find($this->original_vehicle_id);
            if ($original !== null) {
                $original->status = $this->originalGoesToMaintenance()
                    ? Vehicle::STATUS_MAINTENANCE
                    : Vehicle::STATUS_AVAILABLE;
                if ($this->original_return_km !== null && $this->original_return_km > (int) $original->odometer) {
                    $original->odometer = $this->original_return_km;
                }
                $original->save();
            }
        }

        // The order now runs on the replacement car; its handover baseline moves
        // to the replacement so the final return is measured against it.
        if ($this->order_id !== null && $this->replacement_vehicle_id !== null) {
            $order = RentalOrder::query()->find($this->order_id);
            if ($order !== null) {
                $order->vehicle_id = $this->replacement_vehicle_id;
                if ($this->replacement_handover_km !== null) {
                    $order->handover_km = $this->replacement_handover_km;
                }
                if ($this->replacement_handover_fuel !== null) {
                    $order->handover_fuel = $this->replacement_handover_fuel;
                }
                $order->save();
            }
        }
    }

    /**
     * Close the replacement once the original car is back in service. The
     * replacement car stays out with the customer — it is freed by the order's
     * own return, not here.
     */
    public function close(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }

        $this->status = self::STATUS_CLOSED;
        $this->save();

        if ($this->original_vehicle_id !== null) {
            Vehicle::query()->whereKey($this->original_vehicle_id)
                ->where('status', Vehicle::STATUS_MAINTENANCE)
                ->update(['status' => Vehicle::STATUS_AVAILABLE]);
        }
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function reasonTypeOptions(): array
    {
        return [
            ['value' => self::REASON_BREAKDOWN, 'label' => 'Breakdown'],
            ['value' => self::REASON_ACCIDENT, 'label' => 'Accident'],
            ['value' => self::REASON_SERVICE, 'label' => 'Service due'],
            ['value' => self::REASON_CUSTOMER, 'label' => 'Customer request'],
            ['value' => self::REASON_RETURN, 'label' => 'Return to original car'],
            ['value' => self::REASON_UPGRADE, 'label' => 'Upgrade'],
        ];
    }

    public static function reasonTypeLabel(?string $value): string
    {
        foreach (self::reasonTypeOptions() as $option) {
            if ($option['value'] === $value) {
                return $option['label'];
            }
        }

        return '—';
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'rental.replacement',
            name: 'Replacement',
            class: self::class,
            table: 'rental_replacements',
            module: 'rental',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('customer_id', 'Customer', 'many2one', relation: 'rental.customer', sequence: 20),
                new FieldDefinition('original_vehicle_id', 'Original', 'many2one', relation: 'rental.vehicle', sequence: 30),
                new FieldDefinition('replacement_vehicle_id', 'Replacement', 'many2one', relation: 'rental.vehicle', sequence: 40),
                new FieldDefinition('date', 'Date', 'date', sequence: 50),
                new FieldDefinition('status', 'Status', 'selection', selection: [
                    ['value' => self::STATUS_ACTIVE, 'label' => 'Active'],
                    ['value' => self::STATUS_CLOSED, 'label' => 'Closed'],
                ], sequence: 60),
            ],
            views: [
                new ViewDefinition('Replacements', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['reference'],
                    'open' => '/app/rental/replacement/{id}',
                ]),
            ],
        );
    }
}

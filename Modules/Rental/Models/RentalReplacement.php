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
 * A car replacement: the customer's vehicle is swapped for another. Activating
 * marks the replacement rented and the original under maintenance; closing
 * frees both.
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $order_id
 * @property int|null $customer_id
 * @property int|null $original_vehicle_id
 * @property int|null $replacement_vehicle_id
 * @property Carbon|null $date
 * @property string|null $reason
 * @property string $status
 * @property string|null $notes
 * @property-read RentalCustomer|null $customer
 * @property-read Vehicle|null $originalVehicle
 * @property-read Vehicle|null $replacementVehicle
 */
final class RentalReplacement extends Model implements DefinesIrModel
{
    protected $table = 'rental_replacements';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'order_id', 'customer_id', 'original_vehicle_id',
        'replacement_vehicle_id', 'date', 'reason', 'status', 'notes',
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
            'date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (RentalReplacement $replacement): void {
            if ($replacement->reference === null || $replacement->reference === '') {
                $replacement->reference = 'REP/' . str_pad((string) $replacement->id, 5, '0', STR_PAD_LEFT);
                $replacement->saveQuietly();
            }
        });
    }

    /**
     * @return BelongsTo<RentalCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(RentalCustomer::class, 'customer_id');
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

    /** Put the replacement car on the road and the original into maintenance. */
    public function applyStatuses(): void
    {
        if ($this->replacement_vehicle_id !== null) {
            Vehicle::query()->whereKey($this->replacement_vehicle_id)->update(['status' => Vehicle::STATUS_RENTED]);
        }
        if ($this->original_vehicle_id !== null) {
            Vehicle::query()->whereKey($this->original_vehicle_id)->update(['status' => Vehicle::STATUS_MAINTENANCE]);
        }
    }

    /** Close the replacement and free both vehicles. */
    public function close(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }

        $this->status = self::STATUS_CLOSED;
        $this->save();

        foreach ([$this->replacement_vehicle_id, $this->original_vehicle_id] as $vehicleId) {
            if ($vehicleId !== null) {
                Vehicle::query()->whereKey($vehicleId)->update(['status' => Vehicle::STATUS_AVAILABLE]);
            }
        }
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

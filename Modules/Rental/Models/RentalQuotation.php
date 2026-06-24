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
 * A rental quotation: an estimate for a customer. Same maths as an order;
 * accepting it converts to a draft {@see RentalOrder} via convertToOrder().
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $customer_id
 * @property int|null $vehicle_id
 * @property int|null $driver_id
 * @property int|null $branch_id
 * @property int|null $order_id
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $valid_until
 * @property string $rate_type
 * @property float $rate
 * @property int $days
 * @property float $subtotal
 * @property float $discount
 * @property float $deposit
 * @property float $total
 * @property string $status
 * @property string|null $notes
 * @property-read RentalCustomer|null $customer
 * @property-read Vehicle|null $vehicle
 */
final class RentalQuotation extends Model implements DefinesIrModel
{
    protected $table = 'rental_quotations';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CONVERTED = 'converted';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'customer_id', 'vehicle_id', 'driver_id', 'branch_id', 'order_id',
        'start_date', 'end_date', 'valid_until', 'rate_type', 'rate', 'days',
        'subtotal', 'discount', 'deposit', 'total', 'status', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'rate_type' => 'daily',
        'rate' => 0,
        'days' => 0,
        'subtotal' => 0,
        'discount' => 0,
        'deposit' => 0,
        'total' => 0,
        'status' => self::STATUS_DRAFT,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'vehicle_id' => 'integer',
            'driver_id' => 'integer',
            'branch_id' => 'integer',
            'order_id' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'valid_until' => 'date',
            'rate' => 'float',
            'days' => 'integer',
            'subtotal' => 'float',
            'discount' => 'float',
            'deposit' => 'float',
            'total' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (RentalQuotation $quote): void {
            if ($quote->reference === null || $quote->reference === '') {
                $quote->reference = 'QT/' . str_pad((string) $quote->id, 5, '0', STR_PAD_LEFT);
                $quote->saveQuietly();
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
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function durationDays(): int
    {
        if (! $this->start_date instanceof Carbon || ! $this->end_date instanceof Carbon) {
            return 0;
        }

        return max(1, (int) $this->start_date->diffInDays($this->end_date));
    }

    public function billableUnits(): int
    {
        $days = $this->durationDays();

        return match ($this->rate_type) {
            'weekly' => (int) ceil($days / 7),
            'monthly' => (int) ceil($days / 30),
            default => $days,
        };
    }

    public function recalcTotals(): void
    {
        $this->days = $this->durationDays();
        $this->subtotal = round($this->rate * $this->billableUnits(), 3);
        $this->total = round(max(0.0, $this->subtotal - $this->discount), 3);
    }

    /**
     * Spawn a draft rental order from this quotation (idempotent — returns the
     * existing order if already converted).
     */
    public function convertToOrder(): RentalOrder
    {
        if ($this->order_id !== null) {
            $existing = RentalOrder::query()->find($this->order_id);
            if ($existing !== null) {
                return $existing;
            }
        }

        $order = new RentalOrder();
        $order->customer_id = $this->customer_id;
        $order->vehicle_id = $this->vehicle_id;
        $order->driver_id = $this->driver_id;
        $order->branch_id = $this->branch_id;
        $order->start_date = $this->start_date;
        $order->end_date = $this->end_date;
        $order->rate_type = $this->rate_type;
        $order->rate = $this->rate;
        $order->discount = $this->discount;
        $order->deposit = $this->deposit;
        $order->notes = $this->notes;
        $order->recalcTotals();
        $order->save();

        $this->order_id = $order->id;
        $this->status = self::STATUS_CONVERTED;
        $this->save();

        return $order;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_DRAFT, 'label' => 'Draft'],
            ['value' => self::STATUS_SENT, 'label' => 'Sent'],
            ['value' => self::STATUS_ACCEPTED, 'label' => 'Accepted'],
            ['value' => self::STATUS_DECLINED, 'label' => 'Declined'],
            ['value' => self::STATUS_CONVERTED, 'label' => 'Converted'],
        ];
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'rental.quotation',
            name: 'Quotation',
            class: self::class,
            table: 'rental_quotations',
            module: 'rental',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('customer_id', 'Customer', 'many2one', relation: 'rental.customer', sequence: 20),
                new FieldDefinition('vehicle_id', 'Vehicle', 'many2one', relation: 'rental.vehicle', sequence: 30),
                new FieldDefinition('valid_until', 'Valid until', 'date', sequence: 40),
                new FieldDefinition('total', 'Total', 'float', sequence: 50),
                new FieldDefinition('status', 'Status', 'selection', selection: self::statusOptions(), sequence: 60),
            ],
            views: [
                new ViewDefinition('Quotations', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'valid_until', 'label' => 'Valid until', 'format' => 'date'],
                        ['field' => 'total', 'label' => 'Total', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['reference'],
                    'open' => '/app/rental/quotation/{id}',
                ]),
            ],
        );
    }
}

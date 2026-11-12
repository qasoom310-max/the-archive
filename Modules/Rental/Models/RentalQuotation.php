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
 * @property list<array{vehicle_id: int, rate: float}>|null $extra_cars
 * @property int $days
 * @property float $subtotal
 * @property float $discount
 * @property float $deposit
 * @property float $total
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RentalCustomer|null $customer
 * @property-read Vehicle|null $vehicle
 */
final class RentalQuotation extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'rental_quotations';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CONVERTED = 'converted';

    /** Cars one quotation may price: the first one plus up to nine more. */
    public const MAX_CARS = 10;

    /** @var list<string> */
    protected $fillable = [
        'reference', 'customer_id', 'vehicle_id', 'driver_id', 'branch_id', 'order_id',
        'start_date', 'end_date', 'valid_until', 'rate_type', 'rate', 'extra_cars', 'days',
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
            'extra_cars' => 'array',
            'days' => 'integer',
            'subtotal' => 'float',
            'discount' => 'float',
            'deposit' => 'float',
            'total' => 'float',
        ];
    }

    public function referencePrefix(): string
    {
        return 'QT';
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

    /**
     * The cars after the first, cleaned: each with a car and a rate.
     *
     * @return list<array{vehicle_id: int, rate: float}>
     */
    public function extraCars(): array
    {
        $out = [];
        foreach ((array) ($this->extra_cars ?? []) as $car) {
            if (! is_array($car) || ! is_numeric($car['vehicle_id'] ?? null)) {
                continue;
            }
            $out[] = ['vehicle_id' => (int) $car['vehicle_id'], 'rate' => round((float) ($car['rate'] ?? 0), 3)];
        }

        return array_slice($out, 0, self::MAX_CARS - 1);
    }

    /**
     * Every car on the quotation, the first one first, each with its rate.
     *
     * @return list<array{vehicle_id: int|null, rate: float}>
     */
    public function cars(): array
    {
        return [
            ['vehicle_id' => $this->vehicle_id, 'rate' => round((float) $this->rate, 3)],
            ...$this->extraCars(),
        ];
    }

    public function carCount(): int
    {
        return count($this->cars());
    }

    /** All the cars' rates added together: what one billing period costs. */
    public function ratePerUnit(): float
    {
        return round(array_sum(array_column($this->cars(), 'rate')), 3);
    }

    public function recalcTotals(): void
    {
        $this->days = $this->durationDays();
        $this->subtotal = round($this->ratePerUnit() * $this->billableUnits(), 3);
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

        // One order per car: an order rents a single car. The quotation's
        // discount and deposit stay on the first order, as typed.
        foreach ($this->extraCars() as $i => $car) {
            $extra = new RentalOrder();
            $extra->customer_id = $this->customer_id;
            $extra->vehicle_id = $car['vehicle_id'];
            $extra->branch_id = $this->branch_id;
            $extra->start_date = $this->start_date;
            $extra->end_date = $this->end_date;
            $extra->rate_type = $this->rate_type;
            $extra->rate = $car['rate'];
            $extra->discount = 0;
            $extra->deposit = 0;
            $extra->notes = trim(__('From quotation :ref, car :n of :count', [
                'ref' => (string) $this->reference,
                'n' => $i + 2,
                'count' => $this->carCount(),
            ]) . "\n" . (string) ($this->notes ?? ''));
            $extra->recalcTotals();
            $extra->save();
        }

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

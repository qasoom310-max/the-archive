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
 * A rental order: a customer takes a vehicle for a date range at a chosen
 * rate. The state machine (draft → active → closed, or cancelled) keeps the
 * vehicle's availability in sync, and the stored totals feed the dashboard.
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $customer_id
 * @property int|null $vehicle_id
 * @property int|null $driver_id
 * @property int|null $branch_id
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $order_date
 * @property string|null $phone
 * @property string|null $additional_driver
 * @property string|null $additional_driver_license
 * @property int|null $pickup_mileage
 * @property string|null $hired_time
 * @property string $rate_type
 * @property float $rate
 * @property int $days
 * @property float $subtotal
 * @property float $discount
 * @property float $vat_rate
 * @property float $vat_amount
 * @property bool $delivery
 * @property string|null $delivery_location
 * @property float $delivery_charges
 * @property float $deposit
 * @property float $total
 * @property float $advance_amount
 * @property float $balance
 * @property string|null $payment_type
 * @property string $state
 * @property string $payment_status
 * @property string|null $notes
 * @property string|null $cpr_image_path
 * @property string|null $license_image_path
 * @property int|null $handover_km
 * @property string|null $handover_fuel
 * @property string|null $handover_notes
 * @property string|null $handover_video_url
 * @property Carbon|null $started_at
 * @property int|null $return_km
 * @property string|null $return_fuel
 * @property bool $has_damage
 * @property string|null $damage_notes
 * @property string|null $damage_video_url
 * @property Carbon|null $returned_at
 * @property-read RentalCustomer|null $customer
 * @property-read Vehicle|null $vehicle
 * @property-read Driver|null $driver
 * @property-read Branch|null $branch
 */
final class RentalOrder extends Model implements DefinesIrModel
{
    protected $table = 'rental_orders';

    public const STATE_DRAFT = 'draft';

    public const STATE_ACTIVE = 'active';

    public const STATE_CLOSED = 'closed';

    public const STATE_CANCELLED = 'cancelled';

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PAID = 'paid';

    /** Flat VAT applied to rental orders (Bahrain), editable per order. */
    public const DEFAULT_VAT_RATE = 10.0;

    /** Flat delivery fee charged when the Delivery option is ticked (BHD). */
    public const DELIVERY_FEE = 3.0;

    /** @var list<string> */
    protected $fillable = [
        'reference', 'order_date', 'customer_id', 'phone', 'vehicle_id', 'pickup_mileage',
        'driver_id', 'additional_driver', 'additional_driver_license', 'branch_id',
        'start_date', 'end_date', 'hired_time', 'rate_type', 'rate', 'days', 'subtotal',
        'discount', 'vat_rate', 'vat_amount', 'delivery', 'delivery_location', 'delivery_charges', 'deposit', 'total',
        'advance_amount', 'balance', 'payment_type', 'state', 'payment_status', 'notes',
        'cpr_image_path', 'license_image_path',
        'handover_km', 'handover_fuel', 'handover_notes', 'handover_video_url', 'started_at',
        'return_km', 'return_fuel', 'has_damage', 'damage_notes', 'damage_video_url', 'returned_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'rate_type' => 'daily',
        'rate' => 0,
        'days' => 0,
        'subtotal' => 0,
        'discount' => 0,
        'vat_rate' => self::DEFAULT_VAT_RATE,
        'vat_amount' => 0,
        'delivery' => false,
        'delivery_charges' => 0,
        'deposit' => 0,
        'total' => 0,
        'advance_amount' => 0,
        'balance' => 0,
        'state' => self::STATE_DRAFT,
        'payment_status' => self::PAYMENT_UNPAID,
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
            'order_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'pickup_mileage' => 'integer',
            'rate' => 'float',
            'days' => 'integer',
            'subtotal' => 'float',
            'discount' => 'float',
            'vat_rate' => 'float',
            'vat_amount' => 'float',
            'delivery' => 'boolean',
            'delivery_charges' => 'float',
            'deposit' => 'float',
            'total' => 'float',
            'advance_amount' => 'float',
            'balance' => 'float',
            'handover_km' => 'integer',
            'started_at' => 'datetime',
            'return_km' => 'integer',
            'has_damage' => 'boolean',
            'returned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Human reference derived from the id, once it exists.
        static::created(function (RentalOrder $order): void {
            if ($order->reference === null || $order->reference === '') {
                $order->reference = 'RO/' . str_pad((string) $order->id, 5, '0', STR_PAD_LEFT);
                $order->saveQuietly();
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

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** Number of days the vehicle is out (minimum 1). */
    public function durationDays(): int
    {
        if (! $this->start_date instanceof Carbon || ! $this->end_date instanceof Carbon) {
            return 0;
        }

        return max(1, (int) $this->start_date->diffInDays($this->end_date));
    }

    /** Billable units for the chosen rate type (days / weeks / months). */
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
     * Recompute the whole money column from the current inputs:
     * Amount (subtotal) → less Discount → + VAT (vat_rate %) → + delivery =
     * Net Total; Balance = Net Total − Advance.
     */
    public function recalcTotals(): void
    {
        $this->days = $this->durationDays();
        $this->subtotal = round($this->rate * $this->billableUnits(), 3);

        // Delivery is a fixed flat fee, applied only when the option is ticked.
        $this->delivery_charges = $this->delivery ? self::DELIVERY_FEE : 0.0;

        // VAT applies to the whole taxable supply — the rental net of discount
        // PLUS the delivery charge (Bahrain composite-supply treatment).
        $taxable = max(0.0, $this->subtotal - $this->discount) + $this->delivery_charges;
        $this->vat_amount = round($taxable * ($this->vat_rate / 100), 3);
        $this->total = round($taxable + $this->vat_amount, 3);
        $this->balance = round(max(0.0, $this->total - $this->advance_amount), 3);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function paymentTypeOptions(): array
    {
        return [
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'cheque', 'label' => 'Cheque'],
            ['value' => 'credit_card', 'label' => 'Credit Card'],
            ['value' => 'benefitpay', 'label' => 'BenefitPay'],
            ['value' => 'online', 'label' => 'Online'],
        ];
    }

    /**
     * Fuel-gauge levels recorded at handover / return.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function fuelLevelOptions(): array
    {
        return [
            ['value' => 'empty', 'label' => 'Empty'],
            ['value' => 'quarter', 'label' => '¼'],
            ['value' => 'half', 'label' => '½'],
            ['value' => 'three_quarter', 'label' => '¾'],
            ['value' => 'full', 'label' => 'Full'],
        ];
    }

    /** Human fuel-gauge label for a stored value (— when unset). */
    public static function fuelLabel(?string $value): string
    {
        foreach (self::fuelLevelOptions() as $option) {
            if ($option['value'] === $value) {
                return $option['label'];
            }
        }

        return '—';
    }

    /** Hand the vehicle over: draft → active, vehicle becomes rented. */
    public function startRental(): void
    {
        if ($this->state !== self::STATE_DRAFT) {
            return;
        }

        $this->started_at = Carbon::now();
        $this->state = self::STATE_ACTIVE;
        $this->save();

        // Vehicle is now out; push the handover KM onto its odometer so the
        // fleet's reading stays current.
        $attrs = ['status' => Vehicle::STATUS_RENTED];
        if ($this->handover_km !== null) {
            $attrs['odometer'] = $this->handover_km;
        }
        $this->vehicle?->update($attrs);
    }

    /** Receive the vehicle back: active → closed, vehicle becomes available. */
    public function closeRental(): void
    {
        if ($this->state !== self::STATE_ACTIVE) {
            return;
        }

        $this->returned_at = Carbon::now();
        $this->state = self::STATE_CLOSED;
        $this->save();

        // Update the odometer from the return reading — keeps maintenance
        // scheduling (next-service KM) accurate.
        $attrs = ['status' => Vehicle::STATUS_AVAILABLE];
        if ($this->return_km !== null) {
            $attrs['odometer'] = $this->return_km;
        }
        $this->vehicle?->update($attrs);
    }

    /** States that hold a vehicle (block another booking for the same dates). */
    private const HOLDING_STATES = [self::STATE_DRAFT, self::STATE_ACTIVE];

    /**
     * Another open (draft or active) order for the same vehicle whose dates
     * overlap [$start, $end] — i.e. a double-booking. Null when the slot is
     * free. `$exceptId` excludes the order being edited.
     */
    public static function overlappingOpenOrder(int $vehicleId, ?Carbon $start, ?Carbon $end, ?int $exceptId = null): ?self
    {
        if ($start === null || $end === null) {
            return null;
        }

        return self::query()
            ->where('vehicle_id', $vehicleId)
            ->whereIn('state', self::HOLDING_STATES)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            // Ranges overlap when each starts on/before the other ends.
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->first();
    }

    /** Mark this draft's vehicle as Reserved (only if it's otherwise free). */
    public function reserveVehicle(): void
    {
        if ($this->state !== self::STATE_DRAFT || $this->vehicle_id === null) {
            return;
        }

        // Only flip an Available car — never downgrade a Rented / Maintenance one.
        Vehicle::query()
            ->where('id', $this->vehicle_id)
            ->where('status', Vehicle::STATUS_AVAILABLE)
            ->update(['status' => Vehicle::STATUS_RESERVED]);
    }

    /**
     * Release a reserved vehicle back to Available — but only when no other
     * open order still holds it, and only if it's merely Reserved (never touch
     * a Rented / Maintenance car).
     */
    public static function releaseVehicleIfUnheld(int $vehicleId, ?int $exceptId = null): void
    {
        $stillHeld = self::query()
            ->where('vehicle_id', $vehicleId)
            ->whereIn('state', self::HOLDING_STATES)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if (! $stillHeld) {
            Vehicle::query()
                ->where('id', $vehicleId)
                ->where('status', Vehicle::STATUS_RESERVED)
                ->update(['status' => Vehicle::STATUS_AVAILABLE]);
        }
    }

    /**
     * Generate an invoice from this order (idempotent — returns the existing
     * invoice if one was already raised for it).
     */
    public function createInvoice(): RentalInvoice
    {
        $existing = RentalInvoice::query()->where('order_id', $this->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $invoice = new RentalInvoice();
        $invoice->customer_id = $this->customer_id;
        $invoice->order_id = $this->id;
        $invoice->issue_date = Carbon::now();
        $invoice->due_date = Carbon::now()->addWeek();
        $invoice->subtotal = $this->subtotal;
        $invoice->discount = $this->discount;
        $invoice->total = $this->total;
        $invoice->save();

        return $invoice;
    }

    /** Cancel an order; free the vehicle it was holding (rented or reserved). */
    public function cancelOrder(): void
    {
        if (in_array($this->state, [self::STATE_CLOSED, self::STATE_CANCELLED], true)) {
            return;
        }

        $wasActive = $this->state === self::STATE_ACTIVE;
        $vehicleId = $this->vehicle_id;
        $this->state = self::STATE_CANCELLED;
        $this->save();

        if ($wasActive) {
            // The car was physically out → it's back and available.
            $this->vehicle?->update(['status' => Vehicle::STATUS_AVAILABLE]);
        } elseif ($vehicleId !== null) {
            // A draft only held a reservation → release it if nothing else does.
            self::releaseVehicleIfUnheld($vehicleId);
        }
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function stateOptions(): array
    {
        return [
            ['value' => self::STATE_DRAFT, 'label' => 'Draft'],
            ['value' => self::STATE_ACTIVE, 'label' => 'Active'],
            ['value' => self::STATE_CLOSED, 'label' => 'Closed'],
            ['value' => self::STATE_CANCELLED, 'label' => 'Cancelled'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function rateTypeOptions(): array
    {
        return [
            ['value' => 'daily', 'label' => 'Daily'],
            ['value' => 'weekly', 'label' => 'Weekly'],
            ['value' => 'monthly', 'label' => 'Monthly'],
        ];
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'rental.order',
            name: 'Order',
            class: self::class,
            table: 'rental_orders',
            module: 'rental',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('customer_id', 'Customer', 'many2one', relation: 'rental.customer', sequence: 20),
                new FieldDefinition('vehicle_id', 'Vehicle', 'many2one', relation: 'rental.vehicle', sequence: 30),
                new FieldDefinition('start_date', 'Pick-up', 'date', sequence: 40),
                new FieldDefinition('end_date', 'Return', 'date', sequence: 50),
                new FieldDefinition('total', 'Total', 'float', sequence: 60),
                new FieldDefinition('state', 'Status', 'selection', selection: self::stateOptions(), sequence: 70),
                new FieldDefinition('payment_status', 'Payment', 'selection', selection: [
                    ['value' => self::PAYMENT_UNPAID, 'label' => 'Unpaid'],
                    ['value' => self::PAYMENT_PAID, 'label' => 'Paid'],
                ], sequence: 80),
            ],
            views: [
                // Bespoke list/form pages own the routes; this list view exists
                // so the model registers (menu entry + ACL) and any engine
                // consumer has a sane default.
                new ViewDefinition('Orders', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'start_date', 'label' => 'Pick-up', 'format' => 'date', 'sortable' => true],
                        ['field' => 'end_date', 'label' => 'Return', 'format' => 'date'],
                        ['field' => 'total', 'label' => 'Total', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'state', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                        ['field' => 'payment_status', 'label' => 'Payment', 'format' => 'badge'],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['reference'],
                    'open' => '/app/rental/order/{id}',
                ]),
            ],
        );
    }
}

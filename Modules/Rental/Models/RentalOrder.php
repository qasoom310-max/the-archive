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
 * @property float $delivery_charges
 * @property float $deposit
 * @property float $total
 * @property float $advance_amount
 * @property float $balance
 * @property string|null $payment_type
 * @property string $state
 * @property string $payment_status
 * @property string|null $notes
 * @property string|null $image_path
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

    /** @var list<string> */
    protected $fillable = [
        'reference', 'order_date', 'customer_id', 'phone', 'vehicle_id', 'pickup_mileage',
        'driver_id', 'additional_driver', 'additional_driver_license', 'branch_id',
        'start_date', 'end_date', 'hired_time', 'rate_type', 'rate', 'days', 'subtotal',
        'discount', 'vat_rate', 'vat_amount', 'delivery_charges', 'deposit', 'total',
        'advance_amount', 'balance', 'payment_type', 'state', 'payment_status', 'notes', 'image_path',
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
            'delivery_charges' => 'float',
            'deposit' => 'float',
            'total' => 'float',
            'advance_amount' => 'float',
            'balance' => 'float',
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

        $taxable = max(0.0, $this->subtotal - $this->discount);
        $this->vat_amount = round($taxable * ($this->vat_rate / 100), 3);
        $this->total = round($taxable + $this->vat_amount + $this->delivery_charges, 3);
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

    /** Hand the vehicle over: draft → active, vehicle becomes rented. */
    public function startRental(): void
    {
        if ($this->state !== self::STATE_DRAFT) {
            return;
        }

        $this->state = self::STATE_ACTIVE;
        $this->save();
        $this->vehicle?->update(['status' => Vehicle::STATUS_RENTED]);
    }

    /** Receive the vehicle back: active → closed, vehicle becomes available. */
    public function closeRental(): void
    {
        if ($this->state !== self::STATE_ACTIVE) {
            return;
        }

        $this->state = self::STATE_CLOSED;
        $this->save();
        $this->vehicle?->update(['status' => Vehicle::STATUS_AVAILABLE]);
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

    /** Cancel an order; free the vehicle if it had been handed over. */
    public function cancelOrder(): void
    {
        if (in_array($this->state, [self::STATE_CLOSED, self::STATE_CANCELLED], true)) {
            return;
        }

        $wasActive = $this->state === self::STATE_ACTIVE;
        $this->state = self::STATE_CANCELLED;
        $this->save();

        if ($wasActive) {
            $this->vehicle?->update(['status' => Vehicle::STATUS_AVAILABLE]);
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

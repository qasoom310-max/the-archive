<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A limousine trip booking: a customer travels pickup → dropoff at a date-time
 * for a fare. Status moves queue → confirmed → active → completed (or
 * cancelled) and drives the dashboard.
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $customer_id
 * @property int|null $pickup_location_id
 * @property int|null $dropoff_location_id
 * @property Carbon|null $pickup_at
 * @property int|null $passengers
 * @property string|null $car_type
 * @property string|null $driver_name
 * @property float $fare
 * @property string $status
 * @property string $payment_status
 * @property string|null $notes
 * @property-read LimoCustomer|null $customer
 * @property-read LimoLocation|null $pickupLocation
 * @property-read LimoLocation|null $dropoffLocation
 */
final class LimoBooking extends Model implements DefinesIrModel
{
    protected $table = 'limo_bookings';

    public const STATUS_QUEUE = 'queue';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PAID = 'paid';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'customer_id', 'pickup_location_id', 'dropoff_location_id',
        'pickup_at', 'passengers', 'car_type', 'driver_name', 'fare',
        'status', 'payment_status', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'fare' => 0,
        'status' => self::STATUS_QUEUE,
        'payment_status' => self::PAYMENT_UNPAID,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'pickup_location_id' => 'integer',
            'dropoff_location_id' => 'integer',
            'pickup_at' => 'datetime',
            'passengers' => 'integer',
            'fare' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (LimoBooking $booking): void {
            if ($booking->reference === null || $booking->reference === '') {
                $booking->reference = 'BK/' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT);
                $booking->saveQuietly();
            }
        });
    }

    /**
     * @return BelongsTo<LimoCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LimoCustomer::class, 'customer_id');
    }

    /**
     * @return BelongsTo<LimoLocation, $this>
     */
    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(LimoLocation::class, 'pickup_location_id');
    }

    /**
     * @return BelongsTo<LimoLocation, $this>
     */
    public function dropoffLocation(): BelongsTo
    {
        return $this->belongsTo(LimoLocation::class, 'dropoff_location_id');
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->save();
    }

    /**
     * Generate an invoice from this booking (idempotent — returns the existing
     * one if already raised).
     */
    public function createInvoice(): LimoInvoice
    {
        $existing = LimoInvoice::query()->where('booking_id', $this->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $invoice = new LimoInvoice();
        $invoice->customer_id = $this->customer_id;
        $invoice->booking_id = $this->id;
        $invoice->issue_date = Carbon::now();
        $invoice->due_date = Carbon::now()->addWeek();
        $invoice->subtotal = $this->fare;
        $invoice->total = $this->fare;
        $invoice->save();

        return $invoice;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_QUEUE, 'label' => 'Queue'],
            ['value' => self::STATUS_CONFIRMED, 'label' => 'Confirmed'],
            ['value' => self::STATUS_ACTIVE, 'label' => 'Active'],
            ['value' => self::STATUS_COMPLETED, 'label' => 'Completed'],
            ['value' => self::STATUS_CANCELLED, 'label' => 'Cancelled'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function carTypeOptions(): array
    {
        return [
            ['value' => 'sedan', 'label' => 'Sedan'],
            ['value' => 'suv', 'label' => 'SUV'],
            ['value' => 'van', 'label' => 'Van'],
            ['value' => 'luxury', 'label' => 'Luxury'],
        ];
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'limousine.booking',
            name: 'Booking',
            class: self::class,
            table: 'limo_bookings',
            module: 'limousine',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('customer_id', 'Customer', 'many2one', relation: 'limousine.customer', sequence: 20),
                new FieldDefinition('pickup_at', 'Pick-up time', 'datetime', sequence: 30),
                new FieldDefinition('fare', 'Fare', 'float', sequence: 40),
                new FieldDefinition('status', 'Status', 'selection', selection: self::statusOptions(), sequence: 50),
                new FieldDefinition('payment_status', 'Payment', 'selection', selection: [
                    ['value' => self::PAYMENT_UNPAID, 'label' => 'Unpaid'],
                    ['value' => self::PAYMENT_PAID, 'label' => 'Paid'],
                ], sequence: 60),
            ],
            views: [
                new ViewDefinition('Bookings', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'pickup_at', 'label' => 'Pick-up', 'format' => 'datetime', 'sortable' => true],
                        ['field' => 'fare', 'label' => 'Fare', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                        ['field' => 'payment_status', 'label' => 'Payment', 'format' => 'badge'],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['reference'],
                    'open' => '/app/limousine/booking/{id}',
                ]),
            ],
        );
    }
}

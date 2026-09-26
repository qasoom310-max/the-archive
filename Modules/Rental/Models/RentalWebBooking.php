<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * A booking taken on the WordPress website, waiting to become a rental order.
 *
 * One row per WooCommerce LINE ITEM, not per order: a customer can book two
 * cars in one checkout and each is its own job for the desk.
 *
 * What the website can tell us is less than it looks. Its `product` is the
 * thing sold, which on the live site is one generic "Wanaan Booking" rather
 * than a car in the fleet, so matching it to a vehicle is a decision a person
 * makes. Pickup and return details arrive only when the booking product is set
 * up to collect them. That is why {@see payload} keeps the request verbatim —
 * a field the site starts sending is then already here, whether or not a
 * column has been added for it yet.
 *
 * @property int $id
 * @property string $source_reference
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $town
 * @property string|null $product
 * @property int $quantity
 * @property string|null $pickup_location
 * @property string|null $dropoff_location
 * @property \Illuminate\Support\Carbon|null $pickup_at
 * @property \Illuminate\Support\Carbon|null $dropoff_at
 * @property float|null $subtotal
 * @property float|null $total
 * @property string|null $payment_mode
 * @property string|null $payment_status
 * @property string|null $notes
 * @property string $status
 * @property int|null $rental_order_id
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property array<string, mixed>|null $payload
 */
final class RentalWebBooking extends Model implements DefinesIrModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETE = 'complete';

    protected $table = 'rental_web_bookings';

    protected $fillable = [
        'source_reference', 'first_name', 'last_name', 'phone', 'email', 'address', 'town',
        'product', 'quantity', 'pickup_location', 'dropoff_location', 'pickup_at', 'dropoff_at',
        'subtotal', 'total', 'payment_mode', 'payment_status', 'notes',
        'status', 'rental_order_id', 'completed_at', 'payload',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'quantity' => 'integer',
        'pickup_at' => 'datetime',
        'dropoff_at' => 'datetime',
        'subtotal' => 'float',
        'total' => 'float',
        'rental_order_id' => 'integer',
        'completed_at' => 'datetime',
        'payload' => 'array',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'quantity' => 1,
    ];

    /** @return list<array{value: string, label: string}> */
    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => self::STATUS_COMPLETE, 'label' => 'Complete'],
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** The customer's name as the website had it, or a dash. */
    public function customerName(): string
    {
        $name = trim(trim((string) $this->first_name) . ' ' . trim((string) $this->last_name));

        return $name !== '' ? $name : '—';
    }

    /** True when the customer already paid on the website. */
    public function isPaidOnline(): bool
    {
        return strtolower(trim((string) $this->payment_status)) === 'paid';
    }

    /**
     * The order raised from this booking, if one has been.
     *
     * A logical reference, so an order deleted afterwards leaves the booking
     * readable instead of taking it down with it.
     */
    public function order(): ?RentalOrder
    {
        return $this->rental_order_id === null
            ? null
            : RentalOrder::query()->find($this->rental_order_id);
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'rental.web_booking',
            name: 'Web Booking',
            class: self::class,
            table: 'rental_web_bookings',
            module: 'rental',
            fields: [
                new FieldDefinition('source_reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('first_name', 'First name', 'char', sequence: 20),
                new FieldDefinition('last_name', 'Last name', 'char', sequence: 30),
                new FieldDefinition('phone', 'Phone', 'char', sequence: 40),
                new FieldDefinition('email', 'Email', 'char', sequence: 50),
                new FieldDefinition('product', 'Product', 'char', sequence: 60),
                new FieldDefinition('quantity', 'Qty', 'integer', sequence: 70),
                new FieldDefinition('pickup_at', 'From date', 'datetime', sequence: 80),
                new FieldDefinition('dropoff_at', 'To date', 'datetime', sequence: 90),
                new FieldDefinition('total', 'Total', 'float', sequence: 100),
                new FieldDefinition('status', 'Status', 'selection', selection: self::statusOptions(), sequence: 110),
            ],
            views: [
                new ViewDefinition('Web Bookings', 'list', [
                    'columns' => [
                        ['field' => 'source_reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'first_name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'phone', 'label' => 'Phone'],
                        ['field' => 'product', 'label' => 'Product'],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['source_reference', 'first_name', 'last_name', 'phone', 'email'],
                ]),
            ],
        );
    }
}

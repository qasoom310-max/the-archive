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
 * @property string $service
 * @property bool $service_manual
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
 * @property int|null $limo_booking_id
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property array<string, mixed>|null $payload
 */
final class RentalWebBooking extends Model implements DefinesIrModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETE = 'complete';

    /** A car hire: becomes a rental order. */
    public const SERVICE_RENTAL = 'rental';

    /** A chauffeured trip: becomes a limousine booking in the trip queue. */
    public const SERVICE_LIMOUSINE = 'limousine';

    /**
     * The website's fare-finder product. Every limousine trip booked through
     * the fare finder is sold as this one product, with the trip in its name.
     */
    public const FARE_FINDER_SLUG = 'wanaan-booking';

    /**
     * Words that only a limousine trip's name carries. The fare finder names a
     * line "Airport Transfer — Sedan (Ford Taurus 2024)"; a car hire is named
     * after the car alone.
     */
    private const LIMOUSINE_WORDS = [
        'transfer', 'chauffeur', 'hourly', 'per hour', 'limo', 'city to city',
        'to ksa', 'bahrain to', 'saudi', 'airport', 'wanaan booking', 'with driver',
    ];

    protected $table = 'rental_web_bookings';

    protected $fillable = [
        'source_reference', 'service', 'service_manual', 'first_name', 'last_name', 'phone', 'email', 'address', 'town',
        'product', 'quantity', 'pickup_location', 'dropoff_location', 'pickup_at', 'dropoff_at',
        'subtotal', 'total', 'payment_mode', 'payment_status', 'notes',
        'status', 'rental_order_id', 'limo_booking_id', 'completed_at', 'payload',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'quantity' => 'integer',
        'pickup_at' => 'datetime',
        'dropoff_at' => 'datetime',
        'subtotal' => 'float',
        'total' => 'float',
        'rental_order_id' => 'integer',
        'limo_booking_id' => 'integer',
        'service_manual' => 'boolean',
        'completed_at' => 'datetime',
        'payload' => 'array',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'service' => self::SERVICE_RENTAL,
        'service_manual' => false,
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

    /**
     * Rental or limousine, from what the website sent.
     *
     * The plugin says so itself from version 1.1 (`service`); before that, and
     * as a back-stop, the fare finder's product and the words in the line's
     * name decide. A car hire is the default: that is what the old connection
     * carried, and a person can switch a booking either way.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function classify(array $payload, ?string $product): string
    {
        $said = strtolower(trim(is_scalar($payload['service'] ?? null) ? (string) $payload['service'] : ''));
        if (in_array($said, [self::SERVICE_RENTAL, self::SERVICE_LIMOUSINE], true)) {
            return $said;
        }

        $slug = strtolower(trim(is_scalar($payload['product_slug'] ?? null) ? (string) $payload['product_slug'] : ''));
        if ($slug === self::FARE_FINDER_SLUG) {
            return self::SERVICE_LIMOUSINE;
        }

        $name = strtolower((string) $product . ' ' . (is_scalar($payload['item_name'] ?? null) ? (string) $payload['item_name'] : ''));
        foreach (self::LIMOUSINE_WORDS as $word) {
            if (str_contains($name, $word)) {
                return self::SERVICE_LIMOUSINE;
            }
        }

        return self::SERVICE_RENTAL;
    }

    public function isLimousine(): bool
    {
        return $this->service === self::SERVICE_LIMOUSINE;
    }

    /** True once a rental order or a limousine booking has been raised from it. */
    public function isConverted(): bool
    {
        return $this->rental_order_id !== null || $this->limo_booking_id !== null;
    }

    /**
     * The line's own details as the website listed them (what the customer
     * filled in on the product page), for the desk to read.
     *
     * @return list<array{label: string, value: string}>
     */
    public function details(): array
    {
        return \Modules\Rental\Support\WebBookingDetails::lines($this->payload ?? []);
    }

    /**
     * What was booked, without the "O#14949 P#14066" order and product ids the
     * plugin puts in front: "Airport Transfer — Sedan (Ford Taurus 2024)".
     */
    public function productName(): string
    {
        $name = trim(preg_replace('/^(O#\d+\s*)?(P#\d+\s*)?/', '', (string) $this->product) ?? '');

        return $name !== '' ? $name : '—';
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

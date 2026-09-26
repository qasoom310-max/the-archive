<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Contracts\TakesCouponCredit;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $booking_type
 * @property string|null $contact_person
 * @property string|null $company_reference
 * @property string|null $pax_name
 * @property string|null $pax_contact
 * @property string|null $flight_number
 * @property string|null $email
 * @property int|null $pickup_location_id
 * @property int|null $dropoff_location_id
 * @property string|null $pickup_address
 * @property string|null $dropoff_address
 * @property Carbon|null $pickup_at
 * @property Carbon|null $booking_to
 * @property int|null $passengers
 * @property string|null $car_type
 * @property string|null $driver_name
 * @property int $num_cars
 * @property string|null $car_details
 * @property float $fare
 * @property float $amount
 * @property float $discount
 * @property float $advance
 * @property string|null $rate_type
 * @property string|null $payment_method
 * @property string|null $requested_by
 * @property string|null $prepared_by
 * @property string $status
 * @property string $payment_status
 * @property string|null $notes
 * @property Carbon|null $imported_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read LimoCustomer|null $customer
 * @property-read LimoLocation|null $pickupLocation
 * @property-read LimoLocation|null $dropoffLocation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LimoLeg> $legs
 */
final class LimoBooking extends Model implements DefinesIrModel, TakesCouponCredit
{
    use \App\Models\Concerns\HasReference;

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
        'reference', 'booking_type', 'customer_id', 'contact_person', 'company_reference',
        'pax_name', 'pax_contact', 'flight_number', 'email',
        'pickup_location_id', 'dropoff_location_id', 'pickup_address', 'dropoff_address',
        'pickup_at', 'booking_to', 'passengers', 'car_type', 'driver_name',
        'num_cars', 'car_details', 'fare', 'amount', 'discount', 'advance',
        'rate_type', 'payment_method', 'requested_by', 'prepared_by',
        'status', 'payment_status', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'fare' => 0,
        'amount' => 0,
        'discount' => 0,
        'advance' => 0,
        'num_cars' => 1,
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
            'booking_to' => 'datetime',
            'passengers' => 'integer',
            'num_cars' => 'integer',
            'fare' => 'float',
            'amount' => 'float',
            'discount' => 'float',
            'advance' => 'float',
            'imported_at' => 'datetime',
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<LimoLeg, $this>
     */
    public function legs(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(LimoLeg::class, 'legable')->orderBy('sequence');
    }

    /**
     * Grand total = sum of the leg nets that are still BILLABLE; stored on
     * `fare` (and mirrored to amount).
     *
     * A cancelled trip comes off the bill. The customer is not charged for a
     * car that never came, so a booking of three trips with one called off is
     * billed for two — the old sum charged for all three and left the office
     * chasing money that was never owed.
     *
     * One exception, and it is the reason this is a query rather than a sum: a
     * trip cancelled too late for a refund forfeited its payment, and that
     * money came back to the customer as a coupon instead. It was earned on
     * this booking and stays on it; the coupon carries their side. Taking it
     * off the bill as well would hand the same money over twice.
     */
    public function recalcTotal(): void
    {
        $total = round((float) $this->legs()
            ->where(function (Builder $q): void {
                $q->where('status', '!=', LimoLeg::STATUS_CANCELLED)
                    // A leg with no status yet is still to run, and SQL will not
                    // match NULL against '!=' on its own.
                    ->orWhereNull('status')
                    ->orWhereIn('refund_outcome', LimoLeg::BILLABLE_OUTCOMES);
            })
            ->sum('net_amount'), 3);

        $this->fare = $total;
        $this->amount = $total;
    }

    /* ── Credit (see TakesCouponCredit) ─────────────────────────────────── */

    public function couponBalanceDue(): float
    {
        return $this->balanceDue();
    }

    /**
     * Credit lands on the advance, the same field a cash payment lands on, so
     * "paid" settles through one path rather than learning a second way to
     * become true.
     */
    public function applyCouponCredit(float $amount): void
    {
        $this->advance = round((float) $this->advance + $amount, 3);
        $this->save();
        $this->syncPaymentFromAdvance();
    }

    public function couponReference(): string
    {
        return (string) ($this->reference ?? '');
    }

    /** Net booking amount = the grand total across all legs. */
    public function netAmount(): float
    {
        return round((float) $this->fare, 3);
    }

    /** Balance still owed after the advance. */
    public function balanceDue(): float
    {
        return round(max(0.0, $this->netAmount() - $this->advance), 3);
    }

    /**
     * Take the payment flag from the money actually taken.
     *
     * The advance used to be a number nobody read: taking the full fare at the
     * counter left the booking reading "unpaid" until somebody also remembered
     * to press Mark paid. Nothing owed means paid.
     *
     * A part-payment is still unpaid — that is what a deposit is. A booking
     * settled through an INVOICE RECEIPT is left alone: that money did not come
     * through the advance field and must not be un-marked by editing it.
     */
    public function syncPaymentFromAdvance(): void
    {
        if ($this->netAmount() <= 0) {
            return; // nothing priced yet — an empty booking isn't "paid"
        }

        if ($this->balanceDue() <= 0) {
            $this->payment_status = self::PAYMENT_PAID;
            $this->save();

            return;
        }

        $settledByReceipt = LimoInvoice::query()
            ->where('booking_id', $this->id)
            ->where('status', LimoInvoice::STATUS_PAID)
            ->exists();

        if (! $settledByReceipt && $this->payment_status === self::PAYMENT_PAID) {
            // The advance was lowered (a correction), and no receipt backs the
            // paid flag, so the booking owes money again.
            $this->payment_status = self::PAYMENT_UNPAID;
            $this->save();
        }
    }

    public function referencePrefix(): string
    {
        return 'BK';
    }

    /**
     * Re-derive this booking's status from its legs.
     *
     * Legs are dispatched one by one, so the booking is a summary of them: it
     * reads as whatever the LEAST-progressed live leg is. A job with one leg
     * finished and one still waiting is not "completed", it is still in the
     * queue. Cancelled legs are ignored unless every leg is cancelled, and a
     * booking with no legs is left alone.
     */
    public function syncStatusFromLegs(): void
    {
        $statuses = $this->legs()->pluck('status')->filter()->all();
        if ($statuses === []) {
            return;
        }

        $live = array_values(array_filter($statuses, static fn (string $s): bool => $s !== LimoLeg::STATUS_CANCELLED));
        if ($live === []) {
            $this->status = self::STATUS_CANCELLED;
            $this->save();

            return;
        }

        // Ordered least → most progressed; the first one present wins.
        foreach ([
            LimoLeg::STATUS_QUEUE => self::STATUS_QUEUE,
            LimoLeg::STATUS_CONFIRMED => self::STATUS_CONFIRMED,
            LimoLeg::STATUS_ACTIVE => self::STATUS_ACTIVE,
        ] as $legStatus => $bookingStatus) {
            if (in_array($legStatus, $live, true)) {
                $this->status = $bookingStatus;
                $this->save();

                return;
            }
        }

        $this->status = self::STATUS_COMPLETED;
        $this->save();
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
    /**
     * The trip's invoice, raised if it does not exist and kept in step if it
     * does.
     *
     * Every trip is invoiced — a booking with no invoice is money nobody is
     * accounting for — so this runs when the booking is written and again
     * whenever its price moves. The invoice follows the fare only while nothing
     * has been paid against it; after that {@see LimoInvoice::followTotal()}
     * refuses, and a change needs a new document rather than a rewritten one.
     */
    public function syncInvoice(): LimoInvoice
    {
        $invoice = $this->createInvoice();
        $invoice->followTotal((float) $this->fare);

        return $invoice;
    }

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

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function bookingTypeOptions(): array
    {
        return [
            ['value' => 'airport', 'label' => 'Airport transfer'],
            ['value' => 'point_to_point', 'label' => 'Point to point'],
            ['value' => 'hourly', 'label' => 'Hourly / disposal'],
            ['value' => 'full_day', 'label' => 'Full day'],
            ['value' => 'multi_day', 'label' => 'Multi-day'],
            ['value' => 'event', 'label' => 'Event'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function rateTypeOptions(): array
    {
        return [
            ['value' => 'fixed', 'label' => 'Fixed / per trip'],
            ['value' => 'hourly', 'label' => 'Hourly'],
            ['value' => 'daily', 'label' => 'Daily'],
        ];
    }

    /**
     * Payment methods, in the same order the rest of the app uses.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function paymentMethodOptions(): array
    {
        return [
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'cheque', 'label' => 'Cheque'],
            ['value' => 'credit_card', 'label' => 'Credit Card'],
            ['value' => 'benefitpay', 'label' => 'BenefitPay'],
            ['value' => 'online', 'label' => 'Online'],
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

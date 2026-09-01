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
 * A limousine trip quotation. Accepting it converts to a queued
 * {@see LimoBooking} via convertToBooking().
 *
 * @property int $id
 * @property string|null $reference
 * @property Carbon|null $quote_date
 * @property int|null $customer_id
 * @property string|null $contact_person
 * @property string|null $requested_by
 * @property string|null $prepared_by
 * @property string|null $contact_number
 * @property int|null $pickup_location_id
 * @property int|null $dropoff_location_id
 * @property int|null $booking_id
 * @property Carbon|null $pickup_at
 * @property Carbon|null $valid_until
 * @property string|null $car_type
 * @property float $fare
 * @property string $status
 * @property Carbon|null $sent_at
 * @property string|null $sent_to
 * @property string|null $notes
 * @property-read LimoCustomer|null $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LimoLeg> $legs
 */
final class LimoQuotation extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'limo_quotations';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CONVERTED = 'converted';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'quote_date', 'customer_id', 'contact_person', 'requested_by',
        'prepared_by', 'contact_number', 'pickup_location_id', 'dropoff_location_id',
        'booking_id', 'pickup_at', 'valid_until', 'car_type', 'fare', 'status', 'notes',
        'sent_at', 'sent_to',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['fare' => 0, 'status' => self::STATUS_DRAFT];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quote_date' => 'date',
            'customer_id' => 'integer',
            'pickup_location_id' => 'integer',
            'dropoff_location_id' => 'integer',
            'booking_id' => 'integer',
            'pickup_at' => 'datetime',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'fare' => 'float',
        ];
    }

    public function referencePrefix(): string
    {
        return 'QT';
    }

    /**
     * @return BelongsTo<LimoCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LimoCustomer::class, 'customer_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<LimoLeg, $this>
     */
    public function legs(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(LimoLeg::class, 'legable')->orderBy('sequence');
    }

    /** Recalculate the grand total (sum of leg nets) and store it on `fare`. */
    public function recalcTotal(): void
    {
        $this->fare = round((float) $this->legs()->sum('net_amount'), 3);
    }

    /** Spawn a queued booking from this quotation (idempotent). */
    /**
     * Accepting a quote raises the INVOICE, not the trip.
     *
     * The chain the accountant works to is quote → invoice → trip → receipt:
     * the customer agrees a price, we bill it, and the journey is dispatched
     * against that bill. Going straight to a trip skipped the document the
     * money is actually owed under.
     *
     * The quote stays ACCEPTED rather than converted — it becomes converted
     * when the trip is created from the invoice, which is the step that turns
     * a price into a journey.
     */
    /**
     * The invoice this quote was billed as, if it has been accepted.
     *
     * What tells the list a quote has already been processed: `booking_id` no
     * longer does, because the trip is now dispatched from the invoice rather
     * than raised with it — so between the two steps a quote has an invoice and
     * no booking, and would otherwise be offered for processing all over again.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<LimoInvoice, $this>
     */
    public function invoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LimoInvoice::class, 'quotation_id');
    }

    public function convertToInvoice(): LimoInvoice
    {
        $existing = LimoInvoice::query()->where('quotation_id', $this->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $invoice = new LimoInvoice();
        $invoice->customer_id = $this->customer_id;
        $invoice->quotation_id = $this->id;
        $invoice->issue_date = Carbon::now();
        $invoice->due_date = Carbon::now()->addWeek();
        $invoice->subtotal = (float) $this->fare;
        $invoice->total = (float) $this->fare;
        $invoice->notes = $this->notes;
        $invoice->save();

        $this->status = self::STATUS_ACCEPTED;
        $this->save();

        return $invoice;
    }

    public function convertToBooking(): LimoBooking
    {
        if ($this->booking_id !== null) {
            $existing = LimoBooking::query()->find($this->booking_id);
            if ($existing !== null) {
                return $existing;
            }
        }

        $booking = new LimoBooking();
        $booking->customer_id = $this->customer_id;
        $booking->pickup_location_id = $this->pickup_location_id;
        $booking->dropoff_location_id = $this->dropoff_location_id;
        $booking->pickup_at = $this->pickup_at;
        $booking->car_type = $this->car_type;
        $booking->fare = $this->fare;
        $booking->amount = $this->fare;
        $booking->notes = $this->notes;
        $booking->pax_name = $this->contact_person;
        $booking->requested_by = $this->requested_by;
        $booking->prepared_by = $this->prepared_by;
        $booking->save();

        // Copy the priced legs across so the booking carries the same trip plan.
        //
        // Deliberately NOT everything. A leg's reference is unique, so copying
        // it verbatim made converting a quote fail outright — the new trip is a
        // new trip and takes its own running number. The rest left behind is a
        // life the quotation's leg never had: a quote is not dispatched, not
        // signed for and not cancelled, so those fields start empty rather than
        // arriving pre-filled with another row's history.
        foreach ($this->legs as $leg) {
            $copy = $leg->replicate([
                'legable_type', 'legable_id', 'reference', 'status',
                'signature_path', 'signed_at', 'signed_name', 'signed_ip',
                'service_order_sent_at',
                'cancelled_at', 'cancellation_reason', 'refund_outcome', 'refund_amount',
            ]);
            $copy->legable_type = $booking->getMorphClass();
            $copy->legable_id = $booking->id;
            // A booked trip starts in the queue, waiting to be dispatched.
            $copy->status = LimoLeg::STATUS_QUEUE;
            $copy->save();
        }
        if ($this->legs->isNotEmpty()) {
            $booking->recalcTotal(); // keep the header fare for legacy no-leg quotes
            $booking->save();
        }

        $this->booking_id = $booking->id;
        $this->status = self::STATUS_CONVERTED;
        $this->save();

        return $booking;
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
            model: 'limousine.quotation',
            name: 'Quotation',
            class: self::class,
            table: 'limo_quotations',
            module: 'limousine',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('customer_id', 'Customer', 'many2one', relation: 'limousine.customer', sequence: 20),
                new FieldDefinition('valid_until', 'Valid until', 'date', sequence: 30),
                new FieldDefinition('fare', 'Fare', 'float', sequence: 40),
                new FieldDefinition('status', 'Status', 'selection', selection: self::statusOptions(), sequence: 50),
            ],
            views: [
                new ViewDefinition('Quotations', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'valid_until', 'label' => 'Valid until', 'format' => 'date'],
                        ['field' => 'fare', 'label' => 'Fare', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['reference'],
                    'open' => '/app/limousine/quotation/{id}',
                ]),
            ],
        );
    }
}

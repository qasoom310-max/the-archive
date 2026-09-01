<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A limousine invoice. amount_paid is the Σ of its receipts; status is derived
 * (unpaid → partial → paid). Fully paid + tied to a booking → booking marked paid.
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $customer_id
 * @property int|null $booking_id
 * @property int|null $quotation_id
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property float $subtotal
 * @property float $discount
 * @property float $total
 * @property float $amount_paid
 * @property string $status
 * @property string|null $notes
 * @property-read LimoCustomer|null $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LimoReceipt> $receipts
 */
final class LimoInvoice extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'limo_invoices';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'customer_id', 'booking_id', 'quotation_id', 'issue_date', 'due_date',
        'subtotal', 'discount', 'total', 'amount_paid', 'status', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'subtotal' => 0, 'discount' => 0, 'total' => 0, 'amount_paid' => 0,
        'status' => self::STATUS_UNPAID,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'booking_id' => 'integer',
            'quotation_id' => 'integer',
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'float',
            'discount' => 'float',
            'total' => 'float',
            'amount_paid' => 'float',
        ];
    }

    public function referencePrefix(): string
    {
        return 'INV';
    }

    /**
     * @return BelongsTo<LimoCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LimoCustomer::class, 'customer_id');
    }

    /**
     * @return HasMany<LimoReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(LimoReceipt::class, 'invoice_id');
    }

    /**
     * @return BelongsTo<LimoQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(LimoQuotation::class, 'quotation_id');
    }

    /**
     * @return BelongsTo<LimoBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(LimoBooking::class, 'booking_id');
    }

    /**
     * Has money landed against this invoice?
     *
     * Once it has, the document stops following the trip. An invoice somebody
     * has paid against must not change its own total afterwards — the customer
     * holds a receipt quoting a figure, and a document that quietly re-prices
     * itself makes that receipt a lie.
     */
    public function isFrozen(): bool
    {
        return round((float) $this->amount_paid, 3) > 0.0;
    }

    /**
     * Follow the trip's price — but only while nothing is paid.
     *
     * A booking is shaped after it is taken: legs are added, priced, cancelled.
     * The invoice tracks that so the two never disagree, and stops the moment
     * the first payment lands.
     */
    public function followTotal(float $total): bool
    {
        if ($this->isFrozen()) {
            return false;
        }

        $total = round($total, 3);
        if (abs($total - round((float) $this->total, 3)) < 0.0005) {
            return false;
        }

        $this->subtotal = $total;
        $this->total = $total;
        $this->save();

        return true;
    }

    /**
     * Dispatch the journey this invoice bills for.
     *
     * The trip is built from the QUOTATION, because that is what holds the
     * journey — the legs, the route, the hours. An invoice holds only totals,
     * so without the quote behind it there is nothing to build and the office
     * would be retyping a trip it had already priced.
     *
     * Returns null when there is no quotation to build from; idempotent once a
     * trip exists, so pressing twice never raises a second one.
     */
    public function createTrip(): ?LimoBooking
    {
        if ($this->booking_id !== null) {
            return LimoBooking::query()->find($this->booking_id);
        }

        $quotation = $this->quotation;
        if ($quotation === null) {
            return null;
        }

        $booking = $quotation->convertToBooking();

        // Linked BEFORE anything else touches the booking: syncInvoice() finds
        // an invoice by booking_id, so claiming it here is what stops a second
        // invoice being raised for the same trip.
        $this->booking_id = $booking->id;
        $this->save();

        return $booking;
    }

    public function balance(): float
    {
        return round($this->total - $this->amount_paid, 3);
    }

    public function recomputePaid(): void
    {
        $paid = round((float) $this->receipts()->sum('amount'), 3);
        $this->amount_paid = $paid;
        $this->status = match (true) {
            $paid <= 0 => self::STATUS_UNPAID,
            $paid + 0.0005 < $this->total => self::STATUS_PARTIAL,
            default => self::STATUS_PAID,
        };
        $this->save();

        if ($this->booking_id !== null && $this->status === self::STATUS_PAID) {
            LimoBooking::query()->whereKey($this->booking_id)
                ->update(['payment_status' => LimoBooking::PAYMENT_PAID]);
        }
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'limousine.invoice',
            name: 'Invoice',
            class: self::class,
            table: 'limo_invoices',
            module: 'limousine',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('customer_id', 'Customer', 'many2one', relation: 'limousine.customer', sequence: 20),
                new FieldDefinition('issue_date', 'Issued', 'date', sequence: 30),
                new FieldDefinition('total', 'Total', 'float', sequence: 40),
                new FieldDefinition('amount_paid', 'Paid', 'float', sequence: 50),
                new FieldDefinition('status', 'Status', 'selection', selection: [
                    ['value' => self::STATUS_UNPAID, 'label' => 'Unpaid'],
                    ['value' => self::STATUS_PARTIAL, 'label' => 'Partial'],
                    ['value' => self::STATUS_PAID, 'label' => 'Paid'],
                ], sequence: 60),
            ],
            views: [
                new ViewDefinition('Invoices', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'issue_date', 'label' => 'Issued', 'format' => 'date', 'sortable' => true],
                        ['field' => 'total', 'label' => 'Total', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'amount_paid', 'label' => 'Paid', 'format' => 'money', 'align' => 'right'],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['reference'],
                ]),
            ],
        );
    }
}

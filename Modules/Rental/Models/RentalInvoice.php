<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A rental invoice. amount_paid is the Σ of its receipts; status is derived
 * (unpaid → partial → paid). When fully paid and tied to an order, the order
 * is flagged paid too.
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $customer_id
 * @property int|null $order_id
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property float $subtotal
 * @property float $discount
 * @property float $total
 * @property float $amount_paid
 * @property string $status
 * @property string|null $notes
 * @property-read RentalCustomer|null $customer
 * @property-read RentalOrder|null $order
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RentalReceipt> $receipts
 */
final class RentalInvoice extends Model implements DefinesIrModel
{
    protected $table = 'rental_invoices';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'customer_id', 'order_id', 'issue_date', 'due_date',
        'subtotal', 'discount', 'total', 'amount_paid', 'status', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'subtotal' => 0,
        'discount' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'status' => self::STATUS_UNPAID,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'order_id' => 'integer',
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'float',
            'discount' => 'float',
            'total' => 'float',
            'amount_paid' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (RentalInvoice $invoice): void {
            if ($invoice->reference === null || $invoice->reference === '') {
                $invoice->reference = 'INV/' . str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT);
                $invoice->saveQuietly();
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
     * @return BelongsTo<RentalOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'order_id');
    }

    /**
     * @return HasMany<RentalReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(RentalReceipt::class, 'invoice_id');
    }

    public function balance(): float
    {
        return round($this->total - $this->amount_paid, 3);
    }

    /**
     * Recompute amount_paid + status from the receipts, and flag the linked
     * order paid once fully settled.
     */
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

        if ($this->order_id !== null && $this->status === self::STATUS_PAID) {
            RentalOrder::query()->whereKey($this->order_id)
                ->update(['payment_status' => RentalOrder::PAYMENT_PAID]);
        }
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'rental.invoice',
            name: 'Invoice',
            class: self::class,
            table: 'rental_invoices',
            module: 'rental',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('customer_id', 'Customer', 'many2one', relation: 'rental.customer', sequence: 20),
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
                    'open' => '/app/rental/invoice/{id}',
                ]),
            ],
        );
    }
}

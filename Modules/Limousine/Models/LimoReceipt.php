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
 * A payment received against a limousine invoice. Saving/removing recomputes
 * the parent invoice's amount_paid + status.
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $booking_id
 * @property float|null $balance_after
 * @property bool $auto
 * @property int|null $invoice_id
 * @property int|null $customer_id
 * @property Carbon|null $date
 * @property float $amount
 * @property string $method
 * @property string|null $notes
 * @property-read LimoInvoice|null $invoice
 * @property-read LimoCustomer|null $customer
 */
final class LimoReceipt extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'limo_receipts';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'invoice_id', 'booking_id', 'customer_id', 'date',
        'amount', 'balance_after', 'method', 'auto', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['amount' => 0, 'method' => 'cash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_id' => 'integer',
            'booking_id' => 'integer',
            'customer_id' => 'integer',
            'date' => 'date',
            'amount' => 'float',
            'balance_after' => 'float',
            'auto' => 'boolean',
        ];
    }

    public function referencePrefix(): string
    {
        return 'RCP';
    }

    protected static function booted(): void
    {
        static::created(function (LimoReceipt $receipt): void {
            $receipt->invoice?->recomputePaid();
        });

        static::deleted(function (LimoReceipt $receipt): void {
            $receipt->invoice?->recomputePaid();
        });
    }

    /**
     * @return BelongsTo<LimoInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(LimoInvoice::class, 'invoice_id');
    }

    /**
     * The job this money was for.
     *
     * Receipts can exist without an invoice — money is taken on the booking
     * itself — so this is what names the trip on the customer's copy.
     *
     * @return BelongsTo<LimoBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(LimoBooking::class, 'booking_id');
    }

    /**
     * @return BelongsTo<LimoCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LimoCustomer::class, 'customer_id');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function methodOptions(): array
    {
        return [
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'card', 'label' => 'Card'],
            ['value' => 'benefit', 'label' => 'Benefit'],
            ['value' => 'transfer', 'label' => 'Bank transfer'],
        ];
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'limousine.receipt',
            name: 'Receipt',
            class: self::class,
            table: 'limo_receipts',
            module: 'limousine',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('customer_id', 'Customer', 'many2one', relation: 'limousine.customer', sequence: 20),
                new FieldDefinition('date', 'Date', 'date', sequence: 30),
                new FieldDefinition('amount', 'Amount', 'float', sequence: 40),
                new FieldDefinition('method', 'Method', 'selection', selection: self::methodOptions(), sequence: 50),
            ],
            views: [
                new ViewDefinition('Receipts', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
                        ['field' => 'amount', 'label' => 'Amount', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'method', 'label' => 'Method', 'format' => 'badge'],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['reference'],
                    'open' => '/app/limousine/receipt/{id}',
                ]),
            ],
        );
    }
}

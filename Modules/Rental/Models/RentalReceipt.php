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
 * A payment received against a rental invoice. Saving or deleting a receipt
 * recomputes the parent invoice's amount_paid + status.
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $invoice_id
 * @property int|null $customer_id
 * @property Carbon|null $date
 * @property float $amount
 * @property string $method
 * @property string|null $notes
 * @property-read RentalInvoice|null $invoice
 * @property-read RentalCustomer|null $customer
 */
final class RentalReceipt extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'rental_receipts';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'invoice_id', 'customer_id', 'date', 'amount', 'method', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'amount' => 0,
        'method' => 'cash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_id' => 'integer',
            'customer_id' => 'integer',
            'date' => 'date',
            'amount' => 'float',
        ];
    }

    public function referencePrefix(): string
    {
        return 'RCP';
    }

    protected static function booted(): void
    {
        static::created(function (RentalReceipt $receipt): void {
            $receipt->invoice?->recomputePaid();
        });

        static::deleted(function (RentalReceipt $receipt): void {
            $receipt->invoice?->recomputePaid();
        });
    }

    /**
     * @return BelongsTo<RentalInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(RentalInvoice::class, 'invoice_id');
    }

    /**
     * @return BelongsTo<RentalCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(RentalCustomer::class, 'customer_id');
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
            model: 'rental.receipt',
            name: 'Receipt',
            class: self::class,
            table: 'rental_receipts',
            module: 'rental',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('customer_id', 'Customer', 'many2one', relation: 'rental.customer', sequence: 20),
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
                    'open' => '/app/rental/receipt/{id}',
                ]),
            ],
        );
    }
}

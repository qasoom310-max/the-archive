<?php

declare(strict_types=1);

namespace Modules\Purchases\Models;

use App\Erp\Chatter\Chatterable;
use App\Erp\Chatter\HasChatter;
use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Contacts\Models\Partner;
use Modules\Purchases\Enums\PurchaseState;

/**
 * A vendor bill. While Draft it is freely editable; confirming it is handled
 * by {@see \Modules\Purchases\Services\PurchaseConfirmer}, which receives the
 * stock (POS + Inventory) and fires
 * {@see \Modules\Purchases\Events\PurchaseInvoiceConfirmed} for accounting.
 *
 * The column set matches the Accounting listener's `@phpstan-type Invoice`
 * shape so the model can BE the invoice in the event.
 *
 * @property int $id
 * @property string|null $reference
 * @property string|null $name
 * @property int|null $partner_id
 * @property int|null $user_id
 * @property Carbon $date
 * @property Carbon|null $expiry_date
 * @property PurchaseState $state
 * @property bool $is_stock_purchase
 * @property float $total
 * @property float $delivery_cost  Shipping we pay on top of the vendor invoice
 * @property string|null $notes
 * @property Carbon|null $confirmed_at
 * @property-read string|null $partner_name
 */
final class Purchase extends Model implements Chatterable, DefinesIrModel
{
    use HasChatter;

    protected $table = 'purchases';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'name', 'partner_id', 'user_id', 'date', 'expiry_date', 'state',
        'is_stock_purchase', 'total', 'delivery_cost', 'notes', 'confirmed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => 'draft',
        'is_stock_purchase' => true,
        'total' => 0,
        'delivery_cost' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'expiry_date' => 'date',
            'state' => PurchaseState::class,
            'is_stock_purchase' => 'boolean',
            'total' => 'float',
            'delivery_cost' => 'float',
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Give every bill a human reference if none was typed. id is known
        // only post-insert, so fill on `created` and persist quietly.
        static::created(function (Purchase $purchase): void {
            if ($purchase->reference === null || $purchase->reference === '') {
                $year = $purchase->date instanceof Carbon ? $purchase->date->format('Y') : Carbon::now()->format('Y');
                $purchase->reference = sprintf('BILL/%s/%04d', $year, $purchase->getKey());
                $purchase->saveQuietly();
            }
        });
    }

    /**
     * @return HasMany<PurchaseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class, 'purchase_id');
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /** Goods subtotal — the sum of the line subtotals, before delivery. */
    public function goodsSubtotal(): float
    {
        return round((float) $this->lines()->sum('subtotal'), 2);
    }

    /**
     * Recompute the header total from the persisted lines PLUS the delivery
     * cost. Called by the confirmer (and the form on every line edit) so
     * `total` never drifts.
     */
    public function recomputeTotal(): void
    {
        $this->total = round($this->goodsSubtotal() + (float) $this->delivery_cost, 2);
    }

    public function isConfirmed(): bool
    {
        return $this->state === PurchaseState::Confirmed;
    }

    /**
     * Display name of the vendor for the list view; null (empty cell) when
     * no partner is set.
     */
    public function getPartnerNameAttribute(): ?string
    {
        return $this->partner?->name;
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'purchases.purchase',
            name: 'Purchase',
            class: self::class,
            table: 'purchases',
            module: 'purchases',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('partner_id', 'Vendor', 'many2one', relation: 'contacts.partner', sequence: 20),
                new FieldDefinition('date', 'Date', 'date', sequence: 30),
                new FieldDefinition('state', 'Status', 'selection', selection: [
                    ['value' => 'draft', 'label' => 'Draft'],
                    ['value' => 'confirmed', 'label' => 'Confirmed'],
                    ['value' => 'cancelled', 'label' => 'Cancelled'],
                ], sequence: 40),
                new FieldDefinition('total', 'Total', 'float', sequence: 50),
            ],
            views: [
                new ViewDefinition('Purchases', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        // Accessor — sorts by the underlying FK (good enough to
                        // group a vendor's bills together; the engine can't
                        // ORDER BY an accessor).
                        ['field' => 'partner_name', 'label' => 'Vendor', 'sort_field' => 'partner_id'],
                        ['field' => 'date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
                        ['field' => 'state', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                        ['field' => 'total', 'label' => 'Total', 'format' => 'money', 'align' => 'right', 'sum' => true, 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'date', 'dir' => 'desc']],
                    'per_page' => 20,
                    // Rows open the custom line-editor form (not the generic
                    // engine form — a bill has master/detail lines).
                    'open' => '/app/purchases/purchase/{id}',
                ]),
            ],
        );
    }
}

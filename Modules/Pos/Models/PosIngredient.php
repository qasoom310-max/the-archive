<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use App\Erp\Translation\TranslatableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Enums\IngredientMoveKind;
use Spatie\Translatable\HasTranslations;

/**
 * A raw material / ingredient consumed when a product is sold (flour, oil,
 * coffee beans, …). Unlike a {@see PosCondiment}, an ingredient is never sold
 * or offered at the register — it exists only as a stock-tracked recipe
 * component and a purchasable line item. `cost_price` drives stock valuation;
 * `stock_on_hand` is decremented when a product that lists it as a component is
 * sold (see {@see PosOrder::consumeComponents()}).
 *
 * @property int $id
 * @property string $name   Translatable JSON envelope.
 * @property string|null $image_path  Photo of the material (public disk)
 * @property int|null $pos_ingredient_category_id  Managed grouping (Oils, Bottles…)
 * @property float $cost_price
 * @property float $stock_on_hand  On-hand quantity (so an ingredient can be a recipe component)
 * @property float|null $reorder_point  Low-stock threshold; null = global default
 * @property string|null $unit  Size unit: qty|kg|g|l|ml|pcs|box|pack|dozen
 * @property float $pack_size  Size of one stock unit (e.g. 20 for a 20 L drum; 1 = count in the unit)
 * @property float|null $ml_per_unit  Derived: ml in one stock unit (pack_size × the unit)
 * @property int|null $supplier_id  Preferred vendor (logical ref to partners)
 * @property bool $active
 * @property int $sequence
 */
final class PosIngredient extends Model implements DefinesIrModel, TranslatableModel
{
    use HasTranslations;

    protected $table = 'pos_ingredients';

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var list<string> */
    protected $fillable = ['name', 'image_path', 'pos_ingredient_category_id', 'cost_price', 'stock_on_hand', 'reorder_point', 'unit', 'pack_size', 'ml_per_unit', 'supplier_id', 'active', 'sequence'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'active' => true,
        'cost_price' => 0,
        'stock_on_hand' => 0,
        'unit' => 'qty',
        'pack_size' => 1,
        'sequence' => 0,
    ];

    protected static function booted(): void
    {
        // Derive the ml in one stock unit from "each unit is X <unit>" so nobody
        // has to type a raw ml figure. Non-volume units (kg, pcs…) have no ml.
        static::saving(function (self $ingredient): void {
            $size = (float) ($ingredient->pack_size ?? 0);
            if ($size <= 0) {
                $size = 1.0;
            }
            $ingredient->ml_per_unit = match ($ingredient->unit) {
                'l' => $size * 1000,
                'ml' => $size,
                default => null,
            };
        });

        // Correcting a material's cost (e.g. a mistyped ethanol price) must flow
        // through to the perfumes + offers made from it, so their stored cost
        // never disagrees with the live figure. Idempotent + quiet, so no loop.
        static::saved(function (self $ingredient): void {
            // On a genuine cost EDIT only. `wasChanged()` is false on a fresh
            // insert, so a brand-new material (nothing depends on it yet) never
            // triggers this — it fires only when an existing cost actually moves.
            if ($ingredient->wasChanged('cost_price')) {
                app(\Modules\Pos\Services\ProductionCostSync::class)->refreshAll();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_ingredient_category_id' => 'integer',
            'cost_price' => 'float',
            'stock_on_hand' => 'float',
            'reorder_point' => 'float',
            'pack_size' => 'float',
            'ml_per_unit' => 'float',
            'supplier_id' => 'integer',
            'active' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    /** Millilitres in one stock unit (derived from pack_size × the unit). */
    public function mlPerUnit(): float
    {
        $per = (float) ($this->ml_per_unit ?? 0);

        return $per > 0 ? $per : 1.0;
    }

    /** Millilitres available in stock (stock × ml-per-unit). */
    public function availableMl(): float
    {
        return round((float) $this->stock_on_hand * $this->mlPerUnit(), 3);
    }

    /** Cost of one ML (unit cost ÷ ml-per-unit). */
    public function costPerMl(): float
    {
        $per = $this->mlPerUnit();

        return $per > 0 ? (float) $this->cost_price / $per : (float) $this->cost_price;
    }

    /** Deduct `$ml` of consumption from stock, converting to stock units. */
    public function deductMl(float $ml, IngredientMoveKind $kind, ?string $reference = null): void
    {
        $per = $this->mlPerUnit();
        $this->applyStockDelta(-($per > 0 ? $ml / $per : $ml), $kind, $reference);
    }

    /**
     * The single way an ingredient's stock is allowed to move: apply `$delta`
     * (signed, in stock units) and record WHY in `pos_ingredient_moves`.
     *
     * Every caller goes through here so the ledger can never disagree with the
     * on-hand figure — sum the moves and you get `stock_on_hand` back. Before
     * this, five separate places nudged the number directly and nothing recorded
     * the reason, so "how much did we use?" was unanswerable and a stale form
     * write was indistinguishable from a real deduction.
     *
     * A zero delta writes nothing: a no-op move is noise in the history.
     */
    public function applyStockDelta(float $delta, IngredientMoveKind $kind, ?string $reference = null): void
    {
        $delta = round($delta, 3);
        if (abs($delta) < 0.0005) {
            return;
        }

        DB::transaction(function () use ($delta, $kind, $reference): void {
            $this->stock_on_hand = round((float) $this->stock_on_hand + $delta, 3);
            $this->save();

            $this->moves()->create([
                'qty' => $delta,
                'kind' => $kind->value,
                'reference' => $reference,
                'balance_after' => (float) $this->stock_on_hand,
                'user_id' => Auth::id(),
            ]);
        });
    }

    /**
     * Set on-hand to an absolute `$qty`, recording the difference as a move.
     * For re-counts (the Stock Report's Adjust), where the operator knows the
     * true figure rather than the change.
     */
    public function setStockTo(float $qty, IngredientMoveKind $kind, ?string $reference = null): void
    {
        $this->applyStockDelta(round($qty, 3) - (float) $this->stock_on_hand, $kind, $reference);
    }

    /**
     * Accessors so the metadata-driven form can show these as ordinary
     * (read-only) fields — FormView reads values via `getAttribute()`, and the
     * readonly flag keeps them off the save path, so no column is needed.
     */
    public function getPurchasedTotalAttribute(): float
    {
        return $this->purchasedTotal();
    }

    public function getUsedTotalAttribute(): float
    {
        return $this->usedTotal();
    }

    /**
     * @return HasMany<PosIngredientMove, $this>
     */
    public function moves(): HasMany
    {
        return $this->hasMany(PosIngredientMove::class, 'pos_ingredient_id');
    }

    /**
     * Total ever bought, in stock units. Purchases only — an upward adjustment
     * is a re-count, not a purchase, and counting it here would overstate spend.
     */
    public function purchasedTotal(): float
    {
        return round((float) $this->moves()
            ->where('kind', IngredientMoveKind::Purchase->value)
            ->sum('qty'), 3);
    }

    /**
     * Total ever consumed, in stock units, as a POSITIVE number. Production,
     * sales and damages — the three real ways stock leaves. Adjustments are
     * excluded for the same reason as above.
     */
    public function usedTotal(): float
    {
        $kinds = [
            IngredientMoveKind::Production->value,
            IngredientMoveKind::Sale->value,
            IngredientMoveKind::Damage->value,
        ];

        return round(abs((float) $this->moves()->whereIn('kind', $kinds)->sum('qty')), 3);
    }

    /**
     * Cancel this ingredient's recorded consumption out of "Used in total"
     * WITHOUT changing the on-hand count — for usage a since-deleted production
     * recorded, where there's no run left to reverse. Posts a credit that zeroes
     * the consumption tally, plus an equal, opposite Adjustment so the count
     * stays exactly where it is (adjustments aren't counted in "used"). Returns
     * how much was cleared. No-op when nothing is recorded as used.
     */
    public function cancelRecordedUsage(?string $reference = null): float
    {
        $signed = round((float) $this->moves()->whereIn('kind', [
            IngredientMoveKind::Production->value,
            IngredientMoveKind::Sale->value,
            IngredientMoveKind::Damage->value,
        ])->sum('qty'), 3);

        if (abs($signed) < 0.0005) {
            return 0.0;
        }

        // Consumption is negative, so -$signed is a positive Production credit
        // that brings the tally to zero; the opposite Adjustment undoes its
        // effect on the count, leaving on-hand untouched.
        $this->applyStockDelta(-$signed, IngredientMoveKind::Production, $reference);
        $this->applyStockDelta($signed, IngredientMoveKind::Adjustment, $reference);

        return round(abs($signed), 3);
    }

    /**
     * On-hand value = stock × cost price. Drives the Stock Report valuation
     * column + the total inventory value (ingredients, unlike condiments, do
     * carry a tracked cost).
     */
    public function stockValue(): float
    {
        return round((float) $this->stock_on_hand * (float) $this->cost_price, 2);
    }

    /**
     * Managed grouping (Oils, Bottles, Caps…).
     *
     * @return BelongsTo<PosIngredientCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PosIngredientCategory::class, 'pos_ingredient_category_id');
    }

    /** Localised category name for the list view (null = uncategorised). */
    public function getCategoryNameAttribute(): ?string
    {
        return $this->category?->name;
    }

    /**
     * Preferred vendor for restocking this ingredient.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.ingredient',
            name: 'POS Ingredient',
            class: self::class,
            table: 'pos_ingredients',
            module: 'pos',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                // Registry type `binary` → the engine renders an image upload widget.
                new FieldDefinition('image_path', 'Photo', 'binary', sequence: 12),
                new FieldDefinition('pos_ingredient_category_id', 'Category', 'many2one', relation: 'pos.ingredient_category', sequence: 15),
                new FieldDefinition('cost_price', 'Cost Price', 'float', sequence: 20),
                new FieldDefinition('stock_on_hand', 'Stock on hand', 'float', sequence: 25),
                new FieldDefinition('reorder_point', 'Reorder point', 'float', sequence: 28),
                new FieldDefinition('unit', 'Unit', 'selection', sequence: 30),
                new FieldDefinition('pack_size', 'Each unit is', 'float', sequence: 32),
                new FieldDefinition('supplier_id', 'Preferred vendor', 'many2one', relation: 'contacts.partner', sequence: 35),
                new FieldDefinition('sequence', 'Sequence', 'integer', sequence: 40),
            ],
            views: [
                new ViewDefinition('POS Ingredients', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        // `category_name` is an accessor reading through the category
                        // relation; sorts by the underlying FK.
                        ['field' => 'category_name', 'label' => 'Category', 'sort_field' => 'pos_ingredient_category_id'],
                        ['field' => 'cost_price', 'label' => 'Cost', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'stock_on_hand', 'label' => 'Stock', 'format' => 'number', 'align' => 'right', 'sortable' => true],
                        ['field' => 'reorder_point', 'label' => 'Reorder point', 'format' => 'number', 'align' => 'right', 'sortable' => true, 'hidden_by_default' => true],
                        ['field' => 'unit', 'label' => 'Unit', 'hidden_by_default' => true],
                        ['field' => 'sequence', 'label' => 'Sequence', 'align' => 'right', 'sortable' => true, 'hidden_by_default' => true],
                    ],
                    'default_sort' => [['field' => 'sequence', 'dir' => 'asc']],
                    'per_page' => 20,
                    'open' => '/app/pos/ingredient/{id}',
                    'searchable' => ['name'],
                ]),
                new ViewDefinition('POS Ingredient', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'translatable' => true, 'unique' => true],
                        ['field' => 'image_path', 'label' => 'Photo', 'widget' => 'image', 'help' => 'Upload a photo of this material (bottle, cap, oil…).'],
                        [
                            'field' => 'pos_ingredient_category_id',
                            'label' => 'Category',
                            'widget' => 'select',
                            'help' => 'Group this material (Oils, Bottles, Caps…). Manage the list under Ingredient categories.',
                            'optionsFrom' => [
                                'model' => PosIngredientCategory::class,
                                'value' => 'id',
                                'label' => 'name',
                                'orderBy' => 'sequence',
                            ],
                        ],
                        // Read-only on purpose. This form auto-saves on every
                        // keystroke, so a typed on-hand read when the page
                        // opened would flush back over whatever a purchase,
                        // sale or production had moved since — silently undoing
                        // a real deduction. Stock changes through those events,
                        // or the Stock Report's Adjust for a re-count.
                        [
                            'field' => 'stock_on_hand',
                            'label' => 'How many in hand',
                            'widget' => 'number',
                            'readonly' => true,
                            'help' => 'Moves on its own: purchases add, sales and production subtract. To correct a count, use Adjust in the Stock Report.',
                        ],
                        [
                            'field' => 'purchased_total',
                            'label' => 'Bought in total',
                            'widget' => 'number',
                            'readonly' => true,
                            'help' => 'Everything ever received from confirmed purchases.',
                        ],
                        [
                            'field' => 'used_total',
                            'label' => 'Used in total',
                            'widget' => 'number',
                            'readonly' => true,
                            'help' => 'Consumed by production, sales and damages. Re-counts are not counted here.',
                        ],
                        ['field' => 'pack_size', 'label' => 'Each unit is', 'widget' => 'number', 'help' => 'The size of one unit — e.g. 20 for a 20-litre drum. Use 1 if you just count in the unit itself.'],
                        ['field' => 'unit', 'label' => 'Unit', 'widget' => 'select', 'options' => PosProduct::UNIT_OPTIONS],
                        ['field' => 'cost_price', 'label' => 'Cost per unit', 'widget' => 'number', 'help' => 'What one unit costs. Drives stock valuation.'],
                        ['field' => 'reorder_point', 'label' => 'Reorder point', 'widget' => 'number', 'help' => 'Flag as low stock at or below this. Leave blank to use the global default.'],
                        [
                            'field' => 'supplier_id',
                            'label' => 'Preferred vendor',
                            'widget' => 'select',
                            'help' => 'Default supplier shown on the Reorder Report. Leave empty to use the last vendor it was bought from.',
                            'optionsFrom' => [
                                'model' => Partner::class,
                                'value' => 'id',
                                'label' => 'name',
                                'orderBy' => 'name',
                            ],
                        ],
                        ['field' => 'sequence', 'label' => 'Sequence', 'widget' => 'number', 'help' => 'Lower numbers show first in the recipe picker.'],
                    ],
                ]),
            ],
        );
    }
}

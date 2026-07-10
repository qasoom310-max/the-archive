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
use Modules\Contacts\Models\Partner;
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
    protected $fillable = ['name', 'pos_ingredient_category_id', 'cost_price', 'stock_on_hand', 'reorder_point', 'unit', 'pack_size', 'ml_per_unit', 'supplier_id', 'active', 'sequence'];

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
    public function deductMl(float $ml): void
    {
        $per = $this->mlPerUnit();
        $this->stock_on_hand = (float) $this->stock_on_hand - ($per > 0 ? $ml / $per : $ml);
        $this->save();
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
                        ['field' => 'stock_on_hand', 'label' => 'How many in hand', 'widget' => 'number', 'help' => 'How many units / containers you have.'],
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

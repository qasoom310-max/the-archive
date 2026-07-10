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
 * @property float $cost_price
 * @property float $stock_on_hand  On-hand quantity (so an ingredient can be a recipe component)
 * @property float|null $reorder_point  Low-stock threshold; null = global default
 * @property string|null $unit  Unit of measure code: qty|kg|g|l|ml|pcs|box|pack|dozen
 * @property float|null $ml_per_unit  ML in one purchased unit (container size); null = tracked in ML
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
    protected $fillable = ['name', 'cost_price', 'stock_on_hand', 'reorder_point', 'unit', 'ml_per_unit', 'supplier_id', 'active', 'sequence'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'active' => true,
        'cost_price' => 0,
        'stock_on_hand' => 0,
        'unit' => 'qty',
        'sequence' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'float',
            'stock_on_hand' => 'float',
            'reorder_point' => 'float',
            'ml_per_unit' => 'float',
            'supplier_id' => 'integer',
            'active' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    /**
     * Millilitres in one stock unit. Driven by the unit — Liter = 1000, mL = 1 —
     * so the common case needs no extra field. A non-blank `ml_per_unit` overrides
     * it for odd container sizes (e.g. a 750 ml bottle counted as one unit).
     */
    public function mlPerUnit(): float
    {
        $explicit = (float) ($this->ml_per_unit ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        return match ($this->unit) {
            'l' => 1000.0,
            default => 1.0, // 'ml' and anything else: stock is already the consumption unit
        };
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
                new FieldDefinition('cost_price', 'Cost Price', 'float', sequence: 20),
                new FieldDefinition('stock_on_hand', 'Stock on hand', 'float', sequence: 25),
                new FieldDefinition('reorder_point', 'Reorder point', 'float', sequence: 28),
                new FieldDefinition('unit', 'Unit', 'selection', sequence: 30),
                new FieldDefinition('ml_per_unit', 'Millilitres per unit', 'float', sequence: 32),
                new FieldDefinition('supplier_id', 'Preferred vendor', 'many2one', relation: 'contacts.partner', sequence: 35),
                new FieldDefinition('sequence', 'Sequence', 'integer', sequence: 40),
            ],
            views: [
                new ViewDefinition('POS Ingredients', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
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
                        ['field' => 'cost_price', 'label' => 'Cost Price', 'widget' => 'number', 'help' => 'Procurement cost per unit. Drives stock valuation.'],
                        ['field' => 'stock_on_hand', 'label' => 'Stock on hand', 'widget' => 'number', 'help' => 'On-hand quantity, decremented when a product using this ingredient is sold.'],
                        ['field' => 'reorder_point', 'label' => 'Reorder point', 'widget' => 'number', 'help' => 'Flag as low stock at or below this. Leave blank to use the global default.'],
                        ['field' => 'unit', 'label' => 'Unit', 'widget' => 'select', 'options' => PosProduct::UNIT_OPTIONS],
                        ['field' => 'ml_per_unit', 'label' => 'Millilitres per unit (optional)', 'widget' => 'number', 'help' => 'Usually leave blank. Choose Unit = Liter and it counts as 1000 ml automatically (mL = 1). Only fill this for an odd size — e.g. a 750 ml bottle counted as one unit.'],
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

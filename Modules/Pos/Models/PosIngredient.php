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
    protected $fillable = ['name', 'cost_price', 'stock_on_hand', 'reorder_point', 'unit', 'supplier_id', 'active', 'sequence'];

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
            'supplier_id' => 'integer',
            'active' => 'boolean',
            'sequence' => 'integer',
        ];
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
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'translatable' => true],
                        ['field' => 'cost_price', 'label' => 'Cost Price', 'widget' => 'number', 'help' => 'Procurement cost per unit. Drives stock valuation.'],
                        ['field' => 'stock_on_hand', 'label' => 'Stock on hand', 'widget' => 'number', 'help' => 'On-hand quantity, decremented when a product using this ingredient is sold.'],
                        ['field' => 'reorder_point', 'label' => 'Reorder point', 'widget' => 'number', 'help' => 'Flag as low stock at or below this. Leave blank to use the global default.'],
                        ['field' => 'unit', 'label' => 'Unit', 'widget' => 'select', 'options' => PosProduct::UNIT_OPTIONS],
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

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
 * A condiment / add-on the cashier can attach to a cart line at the
 * register (extra cheese, no ice, …). `price` is a per-unit surcharge
 * (0 = free); attaching it to a line raises the line total by price × qty.
 *
 * A condiment may be scoped to a product **category** (`pos_category_id`):
 * the register only offers it for products in that category. A null
 * category = global (shown for every product). Condiments are always
 * active — there is no per-condiment disable; remove it from the list to
 * retire it.
 *
 * @property int $id
 * @property string $name   Translatable JSON envelope.
 * @property float $price
 * @property float $stock_on_hand  On-hand quantity (so a condiment can be a recipe component)
 * @property float|null $reorder_point  Low-stock threshold; null = global default
 * @property int|null $pos_category_id
 * @property int|null $supplier_id  Preferred vendor (logical ref to partners)
 * @property bool $active
 * @property int $sequence
 */
final class PosCondiment extends Model implements DefinesIrModel, TranslatableModel
{
    use HasTranslations;

    protected $table = 'pos_condiments';

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var list<string> */
    protected $fillable = ['name', 'price', 'stock_on_hand', 'reorder_point', 'pos_category_id', 'supplier_id', 'active', 'sequence'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'active' => true,
        'price' => 0,
        'stock_on_hand' => 0,
        'sequence' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'stock_on_hand' => 'float',
            'reorder_point' => 'float',
            'pos_category_id' => 'integer',
            'supplier_id' => 'integer',
            'active' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PosCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PosCategory::class, 'pos_category_id');
    }

    /**
     * Preferred vendor for restocking this condiment.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
    }

    /**
     * Localised category name for the list view (null = global / all
     * products). Reads through the relation, like PosProduct's accessor.
     */
    public function getCategoryNameAttribute(): ?string
    {
        return $this->category?->name;
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.condiment',
            name: 'POS Condiment',
            class: self::class,
            table: 'pos_condiments',
            module: 'pos',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('price', 'Price', 'float', sequence: 20),
                new FieldDefinition('stock_on_hand', 'Stock on hand', 'float', sequence: 25),
                new FieldDefinition('reorder_point', 'Reorder point', 'float', sequence: 28),
                new FieldDefinition('pos_category_id', 'Category', 'many2one', relation: 'pos.category', sequence: 30),
                new FieldDefinition('supplier_id', 'Preferred vendor', 'many2one', relation: 'contacts.partner', sequence: 35),
                new FieldDefinition('sequence', 'Sequence', 'integer', sequence: 40),
            ],
            views: [
                new ViewDefinition('POS Condiments', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'price', 'label' => 'Price', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'stock_on_hand', 'label' => 'Stock', 'format' => 'number', 'align' => 'right', 'sortable' => true],
                        ['field' => 'reorder_point', 'label' => 'Reorder point', 'format' => 'number', 'align' => 'right', 'sortable' => true, 'hidden_by_default' => true],
                        // `category_name` is an accessor reading through the
                        // category relation; sorts by the underlying FK (the
                        // engine can't ORDER BY a computed column).
                        ['field' => 'category_name', 'label' => 'Category', 'sort_field' => 'pos_category_id'],
                        ['field' => 'sequence', 'label' => 'Sequence', 'align' => 'right', 'sortable' => true, 'hidden_by_default' => true],
                    ],
                    'default_sort' => [['field' => 'sequence', 'dir' => 'asc']],
                    'per_page' => 20,
                    'open' => '/app/pos/condiment/{id}',
                    'searchable' => ['name'],
                ]),
                new ViewDefinition('POS Condiment', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'translatable' => true],
                        ['field' => 'price', 'label' => 'Price', 'widget' => 'number', 'help' => 'Per-unit surcharge. Leave 0 for a free add-on / instruction.'],
                        ['field' => 'stock_on_hand', 'label' => 'Stock on hand', 'widget' => 'number', 'help' => 'On-hand quantity, used when this condiment is a recipe component.'],
                        ['field' => 'reorder_point', 'label' => 'Reorder point', 'widget' => 'number', 'help' => 'Flag as low stock at or below this. Leave blank to use the global default.'],
                        [
                            'field' => 'pos_category_id',
                            'label' => 'Category',
                            'widget' => 'select',
                            'help' => 'Only offered for products in this category. Leave empty to show for every product.',
                            'optionsFrom' => [
                                'model' => PosCategory::class,
                                'value' => 'id',
                                'label' => 'name',
                                'orderBy' => 'name',
                            ],
                        ],
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
                        ['field' => 'sequence', 'label' => 'Sequence', 'widget' => 'number', 'help' => 'Lower numbers show first in the register picker.'],
                    ],
                ]),
            ],
        );
    }
}

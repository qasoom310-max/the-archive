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
use Spatie\Translatable\HasTranslations;

/**
 * @property int $id
 * @property string $name       Translatable. Stored as JSON `{"en":..., "ar":...}`;
 *                              read returns the active-locale value
 *                              (`app()->getLocale()`, driven by `company.language`
 *                              via `SetLocale` middleware). Write a single locale
 *                              with `$p->setTranslation('name', 'ar', '…')` or all
 *                              at once with `$p->setTranslations('name', [...])`.
 * @property float $price       Sale price — column kept as `price` for legacy
 *                              compatibility; UI/imports label it "Sale Price".
 * @property float $cost_price  Procurement cost — drives `profit`.
 * @property float $tax_rate
 * @property string|null $barcode
 * @property int|null $pos_category_id
 * @property string|null $image_path
 * @property bool $active
 * @property float $stock_on_hand
 * @property-read int|null $available_servings
 * @property-read float $profit
 */
final class PosProduct extends Model implements DefinesIrModel, TranslatableModel
{
    use HasTranslations;

    protected $table = 'pos_products';

    /**
     * Translatable attributes — Spatie's trait intercepts reads/writes on
     * these and treats the underlying column as JSON keyed by locale. Any
     * other column behaves normally.
     *
     * @var list<string>
     */
    public array $translatable = ['name'];

    /** @var list<string> */
    protected $fillable = [
        'name', 'price', 'cost_price', 'tax_rate', 'barcode',
        'pos_category_id', 'image_path', 'active', 'stock_on_hand',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'cost_price' => 'float',
            'tax_rate' => 'float',
            'active' => 'boolean',
            'stock_on_hand' => 'float',
        ];
    }

    /**
     * Profit per unit = Sale Price − Cost Price. Computed (never stored)
     * so it can't drift from its inputs. Classic accessor (not the new-style
     * `Attribute` cast) to keep Larastan's invariant-generic check happy —
     * see CLAUDE.md project memory on accessor style.
     */
    public function getProfitAttribute(): float
    {
        return (float) $this->price - (float) $this->cost_price;
    }

    /**
     * @return BelongsTo<PosCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PosCategory::class, 'pos_category_id');
    }

    /**
     * Display string for the list-view `category` column. Reads through
     * the relation so it follows whatever the user typed in the category
     * form (and naturally Arabic-locale flips with the active translation
     * once PosCategory.name opts into Spatie). Null when uncategorised —
     * the engine renders an empty cell, not "null".
     */
    public function getCategoryNameAttribute(): ?string
    {
        $cat = $this->category;

        return $cat?->name;
    }

    /**
     * The static recipe (bill of materials) of this finished product.
     *
     * @return HasMany<PosProductRecipe, $this>
     */
    public function recipeLines(): HasMany
    {
        return $this->hasMany(PosProductRecipe::class, 'parent_product_id');
    }

    public function hasRecipe(): bool
    {
        return $this->recipeLines()->exists();
    }

    /**
     * Theoretical yield: how many units of this product can still be made
     * from current ingredient stock. The limiting component caps it.
     * Returns null for products without a recipe (not a made-to-order item).
     */
    public function theoreticalYield(): ?int
    {
        $lines = $this->relationLoaded('recipeLines')
            ? $this->recipeLines
            : $this->recipeLines()->with('component')->get();

        if ($lines->isEmpty()) {
            return null;
        }

        $yield = null;

        foreach ($lines as $line) {
            $component = $line->component;

            if ($component === null || $line->quantity_consumed <= 0) {
                continue;
            }

            $possible = (int) floor($component->stock_on_hand / $line->quantity_consumed);
            $yield = $yield === null ? $possible : min($yield, $possible);
        }

        return $yield;
    }

    /**
     * Display attribute for the inventory/product list ("Available
     * Servings"). Null for products without a recipe.
     */
    public function getAvailableServingsAttribute(): ?int
    {
        return $this->theoreticalYield();
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.product',
            name: 'POS Product',
            class: self::class,
            table: 'pos_products',
            module: 'pos',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('price', 'Sale Price', 'float', sequence: 20),
                new FieldDefinition('cost_price', 'Cost Price', 'float', sequence: 25),
                new FieldDefinition('tax_rate', 'Tax %', 'float', sequence: 30),
                new FieldDefinition('barcode', 'Barcode', 'char', sequence: 40),
                new FieldDefinition('stock_on_hand', 'Stock on hand', 'float', sequence: 50),
                new FieldDefinition('pos_category_id', 'Category', 'many2one', relation: 'pos.category', sequence: 55),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 60),
                // Photo. Registry type `binary` → ViewResolver auto-defaults widget=`image`;
                // the form arch below makes it explicit. Column stays a nullable string path.
                new FieldDefinition('image_path', 'Photo', 'binary', sequence: 65),
            ],
            views: [
                new ViewDefinition('POS Products', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        // `category_name` is an accessor that reads through the
                        // pos_category relation. Sorts by the underlying FK
                        // (groups rows by category in id order — good enough
                        // for the typical "show me products in this category"
                        // glance; alpha-sort would need a join the engine
                        // doesn't synthesise yet).
                        ['field' => 'category_name', 'label' => 'Category', 'sort_field' => 'pos_category_id'],
                        ['field' => 'price', 'label' => 'Sale Price', 'format' => 'money', 'align' => 'right', 'sum' => true, 'sortable' => true],
                        ['field' => 'cost_price', 'label' => 'Cost', 'format' => 'money', 'align' => 'right', 'sum' => true, 'sortable' => true],
                        // `profit` is an accessor — no sum (engine aggregates via
                        // SQL, which can't see a computed column); no sortable
                        // (engine's whitelist-based orderBy needs a real column).
                        // Hidden by default — the typical cashier-led workflow
                        // doesn't surface margin; power users can toggle it on.
                        ['field' => 'profit', 'label' => 'Margin', 'format' => 'money', 'align' => 'right', 'hidden_by_default' => true],
                        ['field' => 'tax_rate', 'label' => 'Tax %', 'format' => 'number', 'align' => 'right', 'hidden_by_default' => true],
                        ['field' => 'stock_on_hand', 'label' => 'Stock', 'format' => 'number', 'align' => 'right', 'sortable' => true, 'hidden_by_default' => true],
                        ['field' => 'available_servings', 'label' => 'Available Servings', 'align' => 'right'],
                        ['field' => 'barcode', 'label' => 'Barcode', 'hidden_by_default' => true],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'bool'],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 20,
                    'open' => '/app/pos/product/{id}',
                ]),
                new ViewDefinition('POS Products', 'kanban', [
                    'card' => ['title' => 'name', 'subtitle' => 'price', 'badges' => ['barcode']],
                    'open' => '/app/pos/product/{id}',
                ]),
                new ViewDefinition('POS Product', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'translatable' => true],
                        ['field' => 'price', 'label' => 'Sale Price', 'widget' => 'number'],
                        ['field' => 'cost_price', 'label' => 'Cost Price', 'widget' => 'number'],
                        ['field' => 'tax_rate', 'label' => 'Tax %', 'widget' => 'number'],
                        ['field' => 'stock_on_hand', 'label' => 'Stock on hand', 'widget' => 'number'],
                        ['field' => 'barcode', 'label' => 'Barcode', 'widget' => 'text'],
                        [
                            'field' => 'pos_category_id',
                            'label' => 'Category',
                            'widget' => 'select',
                            'optionsFrom' => [
                                'model' => PosCategory::class,
                                'value' => 'id',
                                'label' => 'name',
                                'orderBy' => 'name',
                            ],
                        ],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                        // Photo upload — engine FormView renders an avatar preview +
                        // file input, validates `image|max:2048`, and on save stores
                        // under `storage/app/public/pos_products/...` writing the
                        // resulting path back to `image_path`. Works for both new
                        // products and editing existing seeded ("made up") ones.
                        ['field' => 'image_path', 'label' => 'Photo', 'widget' => 'image'],
                    ],
                ]),
            ],
        );
    }
}

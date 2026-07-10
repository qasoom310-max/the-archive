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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Contacts\Models\Partner;
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
 * @property array<int, string>|null $gallery_images  Secondary photo paths (public disk)
 * @property bool $active
 * @property float $stock_on_hand  Shop / display stock the register sells from
 * @property float $store_stock  Back-store stock produced but not yet moved to the shop
 * @property float|null $bottle_size_ml  Fill size of one finished bottle (perfumes)
 * @property string|null $unit  Unit of measure code: qty|kg|g|l|ml|pcs|box|pack|dozen
 * @property float|null $reorder_point  Low-stock threshold; null = global default
 * @property int|null $supplier_id  Preferred vendor (logical ref to partners)
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
        'pos_category_id', 'image_path', 'gallery_images', 'active', 'stock_on_hand', 'unit', 'reorder_point', 'supplier_id',
        'bottle_size_ml', 'store_stock',
    ];

    /**
     * Unit-of-measure options for the product form's "Unit" select +
     * anywhere stock is displayed. Codes are stored verbatim; labels are
     * the human-facing text. `qty` = a plain count (the default).
     *
     * @var list<array{value: string, label: string}>
     */
    public const UNIT_OPTIONS = [
        ['value' => 'qty', 'label' => 'Qty'],
        ['value' => 'pcs', 'label' => 'Pieces (pcs)'],
        ['value' => 'kg', 'label' => 'Kilogram (kg)'],
        ['value' => 'g', 'label' => 'Gram (g)'],
        ['value' => 'l', 'label' => 'Liter (L)'],
        ['value' => 'ml', 'label' => 'Millilitre (mL)'],
        ['value' => 'box', 'label' => 'Box'],
        ['value' => 'pack', 'label' => 'Pack'],
        ['value' => 'dozen', 'label' => 'Dozen'],
    ];

    /**
     * Defaults applied to fresh in-memory instances (before save). The DB
     * column already defaults `active` to true, but Eloquent reads
     * `getAttribute('active')` for a `new PosProduct()` as null — which
     * makes the engine FormView render the checkbox unchecked when a
     * cashier opens "New product". Setting it here means the form starts
     * with the box ticked, matching the persistence default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
        'unit' => 'qty',
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
            'store_stock' => 'float',
            'bottle_size_ml' => 'float',
            'reorder_point' => 'float',
            'supplier_id' => 'integer',
            'gallery_images' => 'array',
        ];
    }

    /**
     * The product's standard production formula (perfumes) — raw materials × ML
     * per batch, used to auto-fill a new production run.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<PosProductFormulaLine, $this>
     */
    public function formulaLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PosProductFormulaLine::class, 'pos_product_id')->orderBy('sequence');
    }

    /**
     * Secondary image paths as a clean list of non-empty strings (the raw
     * cast can hold null or stray empties). Render order = WooCommerce
     * gallery order (after the primary photo).
     *
     * @return list<string>
     */
    public function galleryImages(): array
    {
        return array_values(array_filter(
            $this->gallery_images ?? [],
            static fn (string $path): bool => $path !== '',
        ));
    }

    /**
     * Stock health bucket for this product, reusing the global low-stock
     * threshold unless the product sets its own {@see $reorder_point}.
     * Returns 'out' (≤ 0), 'low' (≤ reorder point), or 'in'. Shared by the
     * Stock Report screen and the daily report so they always agree.
     */
    public function stockStatus(float $globalThreshold): string
    {
        $stock = (float) $this->stock_on_hand;

        if ($stock <= 0) {
            return 'out';
        }

        return $stock <= $this->effectiveReorderPoint($globalThreshold) ? 'low' : 'in';
    }

    public function effectiveReorderPoint(float $globalThreshold): float
    {
        return $this->reorder_point !== null ? (float) $this->reorder_point : $globalThreshold;
    }

    /**
     * On-hand value = stock × cost price. Drives the Stock Report valuation
     * column + the total inventory value.
     */
    public function stockValue(): float
    {
        return round((float) $this->stock_on_hand * (float) $this->cost_price, 2);
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
     * Preferred vendor for restocking this product.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
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
     * Condiments / add-ons explicitly offered for this product at the register
     * (on top of any category-scoped or global condiments). Managed on the
     * product page; surfaced by the terminal's condiment picker.
     *
     * @return BelongsToMany<PosCondiment, $this>
     */
    public function condiments(): BelongsToMany
    {
        return $this->belongsToMany(
            PosCondiment::class,
            'pos_condiment_product',
            'pos_product_id',
            'pos_condiment_id',
        );
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
            : $this->recipeLines()->with(['component', 'condiment', 'ingredient'])->get();

        if ($lines->isEmpty()) {
            return null;
        }

        $yield = null;

        foreach ($lines as $line) {
            $stock = $line->componentStock();

            if ($stock === null || $line->quantity_consumed <= 0) {
                continue;
            }

            $possible = (int) floor($stock / $line->quantity_consumed);
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
                new FieldDefinition('bottle_size_ml', 'Bottle size (ml)', 'float', sequence: 51),
                new FieldDefinition('unit', 'Unit', 'selection', sequence: 52),
                new FieldDefinition('reorder_point', 'Reorder point', 'float', sequence: 54),
                new FieldDefinition('pos_category_id', 'Category', 'many2one', relation: 'pos.category', sequence: 55),
                new FieldDefinition('supplier_id', 'Preferred vendor', 'many2one', relation: 'contacts.partner', sequence: 57),
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
                        ['field' => 'unit', 'label' => 'Unit', 'hidden_by_default' => true],
                        ['field' => 'available_servings', 'label' => 'Available Servings', 'align' => 'right'],
                        ['field' => 'barcode', 'label' => 'Barcode', 'hidden_by_default' => true],
                        // `toggle` makes the column an inline switch — one
                        // click flips the value via ListView::toggleBoolean
                        // (Write-gated server-side). Lets staff hide a
                        // discontinued product from the catalogue without
                        // opening the form.
                        ['field' => 'active', 'label' => 'Active', 'format' => 'toggle'],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 20,
                    'open' => '/app/pos/product/{id}',
                    // Free-text toolbar search. Substring `LIKE '%q%'` across
                    // both fields OR-grouped — `name` is a Spatie translatable
                    // JSON column but the LIKE still substring-matches the raw
                    // envelope, which is fine until Arabic translations land
                    // (then we'll widen to per-locale json_extract paths).
                    'searchable' => ['name', 'barcode'],
                    // Category chip row above the table — one chip per
                    // PosCategory row, ordered by sequence then alphabetic
                    // by the localised name. Tap a chip → list scopes to
                    // products in that category; tap "All" to clear.
                    'filters_dynamic' => [
                        [
                            'name' => 'category',
                            'label' => 'Category',
                            'field' => 'pos_category_id',
                            'optionsFrom' => [
                                'model' => PosCategory::class,
                                'value' => 'id',
                                'label' => 'name',
                                'orderBy' => 'sequence',
                            ],
                        ],
                    ],
                ]),
                new ViewDefinition('POS Products', 'kanban', [
                    // Odoo-style product card: photo hero, name, then a
                    // "Price · Stock" meta footer. No subtitle / badges —
                    // the meta rows carry the same info more legibly.
                    'card' => [
                        'title' => 'name',
                        'image' => 'image_path',
                        'meta' => [
                            ['field' => 'price', 'label' => 'Sale Price', 'format' => 'money'],
                            ['field' => 'stock_on_hand', 'label' => 'On hand', 'format' => 'number'],
                        ],
                    ],
                    'open' => '/app/pos/product/{id}',
                    // Engine renders 12 tiles up-front and lazy-loads
                    // another 12 each time the user scrolls to the
                    // bottom (IntersectionObserver-driven in
                    // kanban-view.blade.php).
                    'per_page' => 12,
                    // Toolbar search: substring `LIKE '%q%'` across both
                    // fields OR-grouped. Same caveat as the list arch —
                    // `name` is a Spatie JSON column so LIKE matches the
                    // raw envelope (fine until Arabic translations land).
                    'searchable' => ['name', 'barcode'],
                ]),
                new ViewDefinition('POS Product', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'translatable' => true],
                        ['field' => 'price', 'label' => 'Sale Price', 'widget' => 'number'],
                        ['field' => 'cost_price', 'label' => 'Cost Price', 'widget' => 'number'],
                        ['field' => 'tax_rate', 'label' => 'Tax %', 'widget' => 'number'],
                        ['field' => 'stock_on_hand', 'label' => 'Stock on hand', 'widget' => 'number'],
                        // Unit of measure shown right beside "Stock on hand" so
                        // staff can stock by weight/volume (kg, L…) not just count.
                        ['field' => 'unit', 'label' => 'Unit', 'widget' => 'select', 'options' => self::UNIT_OPTIONS],
                        // Perfume bottle fill size — production divides the mix by this
                        // to work out how many bottles a batch yields.
                        ['field' => 'bottle_size_ml', 'label' => 'Bottle size (ml)', 'widget' => 'number', 'help' => 'Fill size of one bottle. Used by Production to count bottles from a mix.'],
                        // Optional per-product low-stock level; blank = global default.
                        ['field' => 'reorder_point', 'label' => 'Reorder point', 'widget' => 'number', 'help' => 'Flag as low stock at or below this. Leave blank to use the global default.'],
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

<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use App\Erp\Translation\TranslatableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * A managed category for {@see PosIngredient}s — Oils, Bottles, Caps, Pumps,
 * Boxes, Stickers… The perfume workshop creates and renames its own so it can
 * group raw materials and packaging in the ingredient list and the production
 * pickers. A plain flat list (no nesting): name, sequence, active.
 *
 * @property int $id
 * @property string $name  Translatable JSON envelope.
 * @property bool $active
 * @property int $sequence
 */
final class PosIngredientCategory extends Model implements DefinesIrModel, TranslatableModel
{
    use HasTranslations;

    protected $table = 'pos_ingredient_categories';

    /** @var list<string> */
    protected $fillable = ['name', 'active', 'sequence'];

    /** @var list<string> */
    public array $translatable = ['name'];

    /**
     * Default a fresh category to active (the engine FormView coerces an
     * unticked checkbox to false on save — same pitfall PosCategory fixes).
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['active' => true, 'sequence' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return HasMany<PosIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(PosIngredient::class, 'pos_ingredient_category_id');
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.ingredient_category',
            name: 'POS Ingredient Category',
            class: self::class,
            table: 'pos_ingredient_categories',
            module: 'pos',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('sequence', 'Sequence', 'integer', sequence: 20),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 30),
            ],
            views: [
                new ViewDefinition('POS Ingredient Categories', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'toggle', 'sortable' => true],
                        ['field' => 'sequence', 'label' => 'Sequence', 'format' => 'number', 'align' => 'right', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'sequence', 'dir' => 'asc']],
                    'per_page' => 20,
                    'open' => '/app/pos/ingredient_category/{id}',
                    'searchable' => ['name'],
                ]),
                new ViewDefinition('POS Ingredient Category', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'translatable' => true],
                        ['field' => 'sequence', 'label' => 'Sequence', 'widget' => 'number', 'help' => 'Lower numbers show first.'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox', 'help' => 'Turn off to retire a category you no longer use.'],
                    ],
                ]),
            ],
        );
    }
}

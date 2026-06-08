<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use App\Erp\Translation\TranslatableModel;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * A condiment / add-on the cashier can attach to any cart line at the
 * register (extra cheese, no ice, …). A single global list — every product
 * can offer every active condiment. `price` is a per-unit surcharge (0 =
 * free); attaching it to a line raises the line total by price × qty.
 *
 * @property int $id
 * @property string $name   Translatable JSON envelope.
 * @property float $price
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
    protected $fillable = ['name', 'price', 'active', 'sequence'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'active' => true,
        'price' => 0,
        'sequence' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'active' => 'boolean',
            'sequence' => 'integer',
        ];
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
                new FieldDefinition('active', 'Active', 'boolean', sequence: 30),
                new FieldDefinition('sequence', 'Sequence', 'integer', sequence: 40),
            ],
            views: [
                new ViewDefinition('POS Condiments', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'price', 'label' => 'Price', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'sequence', 'label' => 'Sequence', 'align' => 'right', 'sortable' => true, 'hidden_by_default' => true],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'toggle'],
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
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                        ['field' => 'sequence', 'label' => 'Sequence', 'widget' => 'number', 'help' => 'Lower numbers show first in the register picker.'],
                    ],
                ]),
            ],
        );
    }
}

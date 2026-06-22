<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A table on a floor. `seats` is the capacity (denominator of the floor
 * plan's "guests/seats"); `shape` is a plain string `square|round` (NOT an
 * enum cast — the engine FormView's empty-option/`in:` handling has tripped
 * over enum-cast columns before; a string sidesteps it).
 *
 * @property int $id
 * @property int $pos_floor_id
 * @property string $name
 * @property int $seats
 * @property string $shape
 * @property int|null $pos_x   Grid-cell column on the floor canvas (null = unplaced).
 * @property int|null $pos_y   Grid-cell row on the floor canvas (null = unplaced).
 * @property int $sequence
 * @property bool $active
 */
final class PosTable extends Model implements DefinesIrModel
{
    protected $table = 'pos_tables';

    /** @var list<string> */
    protected $fillable = ['pos_floor_id', 'name', 'seats', 'shape', 'pos_x', 'pos_y', 'sequence', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'seats' => 4,
        'shape' => 'square',
        'sequence' => 0,
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_floor_id' => 'integer',
            'seats' => 'integer',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'sequence' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PosFloor, $this>
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(PosFloor::class, 'pos_floor_id');
    }

    public function getFloorNameAttribute(): ?string
    {
        return $this->floor?->name;
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.table',
            name: 'POS Table',
            class: self::class,
            table: 'pos_tables',
            module: 'pos',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('pos_floor_id', 'Floor', 'many2one', relation: 'pos.floor', sequence: 20),
                new FieldDefinition('seats', 'Seats', 'integer', sequence: 30),
                new FieldDefinition('shape', 'Shape', 'char', sequence: 40),
                new FieldDefinition('sequence', 'Sequence', 'integer', sequence: 50),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 60),
            ],
            views: [
                new ViewDefinition('POS Tables', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'floor_name', 'label' => 'Floor', 'sort_field' => 'pos_floor_id'],
                        ['field' => 'seats', 'label' => 'Seats', 'align' => 'right', 'sortable' => true],
                        ['field' => 'shape', 'label' => 'Shape'],
                        ['field' => 'sequence', 'label' => 'Sequence', 'align' => 'right', 'sortable' => true, 'hidden_by_default' => true],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'toggle'],
                    ],
                    'default_sort' => [['field' => 'sequence', 'dir' => 'asc']],
                    'per_page' => 30,
                    'open' => '/app/pos/table/{id}',
                    'searchable' => ['name'],
                ]),
                new ViewDefinition('POS Table', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true],
                        [
                            'field' => 'pos_floor_id',
                            'label' => 'Floor',
                            'widget' => 'select',
                            'optionsFrom' => [
                                'model' => PosFloor::class,
                                'value' => 'id',
                                'label' => 'name',
                                'orderBy' => 'sequence',
                            ],
                        ],
                        ['field' => 'seats', 'label' => 'Seats', 'widget' => 'number', 'help' => 'Capacity — the denominator in the floor plan (e.g. 4 in "2/4").'],
                        [
                            'field' => 'shape',
                            'label' => 'Shape',
                            'widget' => 'select',
                            'options' => [
                                ['value' => 'square', 'label' => 'Square'],
                                ['value' => 'round', 'label' => 'Round'],
                            ],
                        ],
                        ['field' => 'sequence', 'label' => 'Sequence', 'widget' => 'number', 'help' => 'Lower numbers show first on the floor.'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                    ],
                ]),
            ],
        );
    }
}

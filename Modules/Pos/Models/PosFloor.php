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
 * A restaurant floor (Main floor, Patio, …) — a tab above the table picker
 * holding a set of {@see PosTable}s.
 *
 * @property int $id
 * @property string $name   Translatable JSON envelope ({"en":…,"ar":…}).
 * @property int $sequence
 * @property bool $active
 */
final class PosFloor extends Model implements DefinesIrModel, TranslatableModel
{
    use HasTranslations;

    protected $table = 'pos_floors';

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var list<string> */
    protected $fillable = ['name', 'sequence', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'active' => true,
        'sequence' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PosTable, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(PosTable::class, 'pos_floor_id');
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.floor',
            name: 'POS Floor',
            class: self::class,
            table: 'pos_floors',
            module: 'pos',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('sequence', 'Sequence', 'integer', sequence: 20),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 30),
            ],
            views: [
                new ViewDefinition('POS Floors', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'sequence', 'label' => 'Sequence', 'align' => 'right', 'sortable' => true],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'toggle'],
                    ],
                    'default_sort' => [['field' => 'sequence', 'dir' => 'asc']],
                    'per_page' => 20,
                    'open' => '/app/pos/floor/{id}',
                    'searchable' => ['name'],
                ]),
                new ViewDefinition('POS Floor', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'translatable' => true],
                        ['field' => 'sequence', 'label' => 'Sequence', 'widget' => 'number', 'help' => 'Lower numbers show first.'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                    ],
                ]),
            ],
        );
    }
}

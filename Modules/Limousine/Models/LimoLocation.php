<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * A pickup / dropoff location (airport, hotel, area).
 *
 * @property int $id
 * @property string $name
 * @property string|null $area
 * @property string|null $notes
 * @property bool $active
 */
final class LimoLocation extends Model implements DefinesIrModel
{
    protected $table = 'limo_locations';

    /** @var list<string> */
    protected $fillable = ['name', 'area', 'notes', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'limousine.location',
            name: 'Location',
            class: self::class,
            table: 'limo_locations',
            module: 'limousine',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('area', 'Area', 'char', sequence: 20),
                new FieldDefinition('notes', 'Notes', 'text', sequence: 30),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 40),
            ],
            views: [
                new ViewDefinition('Locations', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'area', 'label' => 'Area', 'sortable' => true],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'bool'],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 20,
                    'searchable' => ['name', 'area'],
                    'open' => '/app/limousine/location/{id}',
                ]),
                new ViewDefinition('Location', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true],
                        ['field' => 'area', 'label' => 'Area', 'widget' => 'text'],
                        ['field' => 'notes', 'label' => 'Notes', 'widget' => 'textarea'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                    ],
                ]),
            ],
        );
    }
}

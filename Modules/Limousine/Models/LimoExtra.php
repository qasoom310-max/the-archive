<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use App\Erp\Translation\TranslatableModel;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Something sold with a trip on top of the fare: a child seat, Causeway Plus,
 * a luggage helper. This is the price list; a trip keeps its own copy of the
 * name and price it was sold at ({@see LimoLeg::$extras}).
 *
 * @property int $id
 * @property string $name   Translatable JSON envelope
 * @property float $price
 * @property bool $price_editable  The trip form fills the price and lets the office change it
 * @property bool $active
 * @property int $sequence
 */
final class LimoExtra extends Model implements DefinesIrModel, TranslatableModel
{
    use HasTranslations;

    protected $table = 'limo_extras';

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var list<string> */
    protected $fillable = ['name', 'price', 'price_editable', 'active', 'sequence'];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true, 'price' => 0, 'price_editable' => false, 'sequence' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'float', 'price_editable' => 'boolean', 'active' => 'boolean', 'sequence' => 'integer'];
    }

    /** The name the bill prints: English, which the PDF font can draw. */
    public function billName(): string
    {
        $en = trim((string) $this->getTranslation('name', 'en', false));

        return $en !== '' ? $en : (string) $this->name;
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'limousine.extra',
            name: 'Trip extra',
            class: self::class,
            table: 'limo_extras',
            module: 'limousine',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('price', 'Price', 'float', sequence: 20),
                new FieldDefinition('price_editable', 'Price can be changed on the trip', 'boolean', sequence: 25),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 30),
                new FieldDefinition('sequence', 'Sequence', 'integer', sequence: 40),
            ],
            views: [
                new ViewDefinition('Trip extras', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'price', 'label' => 'Price', 'format' => 'money', 'sortable' => true],
                        ['field' => 'price_editable', 'label' => 'Price can be changed on the trip', 'format' => 'toggle'],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'toggle'],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 20,
                    'searchable' => ['name'],
                    'open' => '/app/limousine/extra/{id}',
                ]),
                new ViewDefinition('Trip extra', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'translatable' => true],
                        ['field' => 'price', 'label' => 'Price', 'widget' => 'number'],
                        ['field' => 'price_editable', 'label' => 'Price can be changed on the trip', 'widget' => 'checkbox', 'help' => 'The price above is filled in on the trip, and the office can type a different one.'],
                        ['field' => 'sequence', 'label' => 'Sequence', 'widget' => 'number', 'help' => 'Lower numbers come first in the list.'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                    ],
                ]),
            ],
        );
    }
}

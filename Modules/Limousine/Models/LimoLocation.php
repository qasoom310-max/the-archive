<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use App\Models\Concerns\HasCountryFlag;
use Illuminate\Database\Eloquent\Model;

/**
 * A pickup / dropoff location (airport, hotel, area, city).
 *
 * @property int $id
 * @property string $name
 * @property string|null $area
 * @property string|null $country  ISO-2 (BH, SA…): a trip picks the country first, then the place
 * @property string|null $notes
 * @property bool $active
 * @property-read string|null $country_name
 */
final class LimoLocation extends Model implements DefinesIrModel
{
    use HasCountryFlag;

    public const DEFAULT_COUNTRY = 'BH';

    protected $table = 'limo_locations';

    /** @var list<string> */
    protected $fillable = ['name', 'area', 'country', 'notes', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true, 'country' => self::DEFAULT_COUNTRY];

    /**
     * The countries a place can be in, for a select.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function countryOptions(): array
    {
        return array_map(
            static fn (array $c): array => ['value' => $c['code'], 'label' => $c['name']],
            self::countries(),
        );
    }

    /** The ISO-2 code if it is one of ours, else null. */
    public static function knownCountry(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        foreach (self::countries() as $c) {
            if ($c['code'] === $code) {
                return $code;
            }
        }

        return null;
    }

    public function getCountryNameAttribute(): ?string
    {
        $name = $this->countryName();

        return $name !== null ? __($name) : null;
    }

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
                new FieldDefinition('country', 'Country', 'char', required: true, sequence: 25),
                new FieldDefinition('notes', 'Notes', 'text', sequence: 30),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 40),
            ],
            views: [
                new ViewDefinition('Locations', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'area', 'label' => 'Area', 'sortable' => true],
                        ['field' => 'country_name', 'label' => 'Country', 'sortable' => true, 'sort_field' => 'country'],
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
                        ['field' => 'country', 'label' => 'Country', 'widget' => 'select', 'required' => true, 'options' => self::countryOptions()],
                        ['field' => 'notes', 'label' => 'Notes', 'widget' => 'textarea'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                    ],
                ]),
            ],
        );
    }
}

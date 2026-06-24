<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fleet vehicle. Belongs to a branch, carries a full rate card and a
 * security deposit, and a live status that drives the dashboard KPIs.
 *
 * @property int $id
 * @property string $name
 * @property string|null $plate_no
 * @property int|null $branch_id
 * @property string|null $make
 * @property string|null $model
 * @property int|null $year
 * @property string|null $color
 * @property string|null $category
 * @property string $status
 * @property float $daily_rate
 * @property float $weekly_rate
 * @property float $monthly_rate
 * @property float $deposit
 * @property int|null $odometer
 * @property bool $active
 */
final class Vehicle extends Model implements DefinesIrModel
{
    protected $table = 'rental_vehicles';

    /** Status values a vehicle moves through. */
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_RENTED = 'rented';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_RESERVED = 'reserved';

    /** @var list<string> */
    protected $fillable = [
        'name', 'plate_no', 'branch_id', 'make', 'model', 'year', 'color',
        'category', 'status', 'daily_rate', 'weekly_rate', 'monthly_rate',
        'deposit', 'odometer', 'active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_AVAILABLE,
        'daily_rate' => 0,
        'weekly_rate' => 0,
        'monthly_rate' => 0,
        'deposit' => 0,
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'year' => 'integer',
            'daily_rate' => 'float',
            'weekly_rate' => 'float',
            'monthly_rate' => 'float',
            'deposit' => 'float',
            'odometer' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Selectable status options (shared by the form widget and the registry).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_AVAILABLE, 'label' => 'Available'],
            ['value' => self::STATUS_RENTED, 'label' => 'Rented'],
            ['value' => self::STATUS_MAINTENANCE, 'label' => 'Maintenance'],
            ['value' => self::STATUS_RESERVED, 'label' => 'Reserved'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function categoryOptions(): array
    {
        return [
            ['value' => 'economy', 'label' => 'Economy'],
            ['value' => 'sedan', 'label' => 'Sedan'],
            ['value' => 'suv', 'label' => 'SUV'],
            ['value' => 'luxury', 'label' => 'Luxury'],
            ['value' => 'van', 'label' => 'Van'],
            ['value' => 'bus', 'label' => 'Bus'],
        ];
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'rental.vehicle',
            name: 'Vehicle',
            class: self::class,
            table: 'rental_vehicles',
            module: 'rental',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('plate_no', 'Plate no.', 'char', sequence: 20),
                new FieldDefinition('branch_id', 'Branch', 'many2one', relation: 'rental.branch', sequence: 30),
                new FieldDefinition('category', 'Category', 'selection', selection: self::categoryOptions(), sequence: 40),
                new FieldDefinition('status', 'Status', 'selection', selection: self::statusOptions(), sequence: 50),
                new FieldDefinition('daily_rate', 'Daily rate', 'float', sequence: 60),
                new FieldDefinition('weekly_rate', 'Weekly rate', 'float', sequence: 70),
                new FieldDefinition('monthly_rate', 'Monthly rate', 'float', sequence: 80),
                new FieldDefinition('deposit', 'Deposit', 'float', sequence: 90),
                new FieldDefinition('make', 'Make', 'char', sequence: 100),
                new FieldDefinition('model', 'Model', 'char', sequence: 110),
                new FieldDefinition('year', 'Year', 'integer', sequence: 120),
                new FieldDefinition('color', 'Colour', 'char', sequence: 130),
                new FieldDefinition('odometer', 'Odometer', 'integer', sequence: 140),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 150),
            ],
            views: [
                new ViewDefinition('Vehicles', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'plate_no', 'label' => 'Plate'],
                        ['field' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                        ['field' => 'daily_rate', 'label' => 'Daily', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'bool'],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 20,
                    'searchable' => ['name', 'plate_no', 'make', 'model'],
                    'open' => '/app/rental/vehicle/{id}',
                ]),
                new ViewDefinition('Vehicle', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'placeholder' => 'e.g. Toyota Yaris 2023'],
                        ['field' => 'plate_no', 'label' => 'Plate no.', 'widget' => 'text'],
                        [
                            'field' => 'branch_id',
                            'label' => 'Branch',
                            'widget' => 'select',
                            'optionsFrom' => ['model' => Branch::class, 'value' => 'id', 'label' => 'name', 'orderBy' => 'name'],
                        ],
                        ['field' => 'category', 'label' => 'Category', 'widget' => 'select', 'options' => self::categoryOptions()],
                        ['field' => 'status', 'label' => 'Status', 'widget' => 'select', 'options' => self::statusOptions()],
                        ['field' => 'daily_rate', 'label' => 'Daily rate (BHD)', 'widget' => 'number'],
                        ['field' => 'weekly_rate', 'label' => 'Weekly rate (BHD)', 'widget' => 'number'],
                        ['field' => 'monthly_rate', 'label' => 'Monthly rate (BHD)', 'widget' => 'number'],
                        ['field' => 'deposit', 'label' => 'Deposit (BHD)', 'widget' => 'number'],
                        ['field' => 'make', 'label' => 'Make', 'widget' => 'text'],
                        ['field' => 'model', 'label' => 'Model', 'widget' => 'text'],
                        ['field' => 'year', 'label' => 'Year', 'widget' => 'number'],
                        ['field' => 'color', 'label' => 'Colour', 'widget' => 'text'],
                        ['field' => 'odometer', 'label' => 'Odometer', 'widget' => 'number'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                    ],
                ]),
            ],
        );
    }
}

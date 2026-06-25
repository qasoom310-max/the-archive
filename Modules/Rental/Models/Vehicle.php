<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property string|null $fuel_type
 * @property string $status
 * @property float $daily_rate
 * @property float $weekly_rate
 * @property float $monthly_rate
 * @property float $deposit
 * @property int|null $odometer
 * @property Carbon|null $next_maintenance_date
 * @property int|null $next_maintenance_mileage
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
        'category', 'fuel_type', 'status', 'daily_rate', 'weekly_rate', 'monthly_rate',
        'deposit', 'odometer', 'next_maintenance_date', 'next_maintenance_mileage', 'active',
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
            'next_maintenance_date' => 'date',
            'next_maintenance_mileage' => 'integer',
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
     * Display label used everywhere a vehicle is listed — includes the plate
     * number and colour so cars of the same brand/model are told apart
     * (e.g. "Eco Sport · 123456 · White"). Empty parts are skipped.
     */
    public function displayName(): string
    {
        $parts = array_filter([$this->name, $this->plate_no, $this->color], static fn (?string $p): bool => $p !== null && $p !== '');

        return implode(' · ', $parts);
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

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function fuelTypeOptions(): array
    {
        return [
            ['value' => 'petrol', 'label' => 'Petrol'],
            ['value' => 'diesel', 'label' => 'Diesel'],
            ['value' => 'hybrid', 'label' => 'Hybrid'],
            ['value' => 'electric', 'label' => 'Electric'],
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
                new FieldDefinition('fuel_type', 'Fuel type', 'selection', selection: self::fuelTypeOptions(), sequence: 135),
                new FieldDefinition('odometer', 'KM', 'integer', sequence: 140),
                new FieldDefinition('next_maintenance_date', 'Next maintenance date', 'date', sequence: 142),
                new FieldDefinition('next_maintenance_mileage', 'Next maintenance KM', 'integer', sequence: 144),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 150),
            ],
            views: [
                new ViewDefinition('Vehicles', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'plate_no', 'label' => 'Plate'],
                        ['field' => 'color', 'label' => 'Colour'],
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
                        ['field' => 'fuel_type', 'label' => 'Fuel type', 'widget' => 'select', 'options' => self::fuelTypeOptions()],
                        ['field' => 'odometer', 'label' => 'Current KM', 'widget' => 'number'],
                        ['field' => 'next_maintenance_date', 'label' => 'Next maintenance date', 'widget' => 'date'],
                        ['field' => 'next_maintenance_mileage', 'label' => 'Next maintenance KM', 'widget' => 'number'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                    ],
                ]),
            ],
        );
    }
}

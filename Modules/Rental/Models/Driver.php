<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * A driver available for with-driver rentals / deliveries.
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $cpr
 * @property string|null $license_no
 * @property string|null $nationality
 * @property string|null $cpr_doc
 * @property \Illuminate\Support\Carbon|null $license_expiry
 * @property string|null $license_doc
 * @property bool $active
 * @property string $pay_type
 * @property float|null $commission_amount
 */
final class Driver extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\ActiveOrSelected;
    use \App\Models\Concerns\GuardsDeletionWhenReferenced;
    use \Modules\Rental\Models\Concerns\DriverDeletionReferences;
    use \Modules\Rental\Models\Concerns\HasDriverLicence;
    use \Modules\Rental\Models\Concerns\HasDriverPay;

    protected $table = 'rental_drivers';

    /** @var list<string> */
    protected $fillable = [
        'name', 'phone', 'cpr', 'cpr_doc',
        'license_no', 'license_expiry', 'license_doc',
        'nationality', 'active', 'pay_type', 'commission_amount',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true, 'pay_type' => self::PAY_TYPE_COMPANY];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'license_expiry' => 'date', 'commission_amount' => 'float'];
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'rental.driver',
            name: 'Driver',
            class: self::class,
            table: 'rental_drivers',
            module: 'rental',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('phone', 'Phone', 'char', sequence: 20),
                new FieldDefinition('cpr', 'CPR / ID', 'char', sequence: 30),
                new FieldDefinition('license_no', 'Licence no.', 'char', sequence: 40),
                new FieldDefinition('license_expiry', 'Licence expires', 'date', sequence: 45),
                new FieldDefinition('license_doc', 'Licence copy', 'char', sequence: 46),
                new FieldDefinition('cpr_doc', 'CPR copy', 'char', sequence: 35),
                new FieldDefinition('nationality', 'Nationality', 'char', sequence: 50),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 60),
                new FieldDefinition('pay_type', 'Pay type', 'char', required: true, sequence: 65),
                new FieldDefinition('commission_amount', 'Commission (BD)', 'float', sequence: 66),
            ],
            views: [
                new ViewDefinition('Drivers', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'phone', 'label' => 'Phone'],
                        ['field' => 'license_no', 'label' => 'Licence no.'],
                        ['field' => 'license_expiry', 'label' => 'Licence expires', 'sortable' => true],
                        ['field' => 'nationality', 'label' => 'Nationality', 'sortable' => true],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'bool'],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 20,
                    'searchable' => ['name', 'phone', 'cpr', 'license_no'],
                    'open' => '/app/rental/driver/{id}',
                ]),
                new ViewDefinition('Driver', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true],
                        ['field' => 'phone', 'label' => 'Phone', 'widget' => 'tel'],
                        ['field' => 'cpr', 'label' => 'CPR / ID', 'widget' => 'text'],
                        ['field' => 'license_no', 'label' => 'Licence no.', 'widget' => 'text'],
                        ['field' => 'license_expiry', 'label' => 'Licence expires', 'widget' => 'date', 'help' => 'An expired licence blocks the driver from being given a trip.'],
                        ['field' => 'license_doc', 'label' => 'Licence copy', 'widget' => 'file'],
                        ['field' => 'cpr_doc', 'label' => 'CPR copy', 'widget' => 'file'],
                        ['field' => 'nationality', 'label' => 'Nationality', 'widget' => 'text'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                        ['field' => 'pay_type', 'label' => 'Pay type', 'widget' => 'select', 'required' => true, 'options' => self::PAY_TYPE_OPTIONS],
                        ['field' => 'commission_amount', 'label' => 'Commission (BD)', 'widget' => 'number', 'placeholder' => '0.000', 'help' => 'Flat commission per trip, in Bahraini Dinar. Only applies to commission drivers — cleared automatically for a company driver.'],
                    ],
                ]),
            ],
        );
    }
}

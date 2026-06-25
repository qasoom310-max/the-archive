<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Modules\Rental\Models\Concerns\DerivesServiceTag;

/**
 * A transport customer — the SHARED customer store for both the Rent A Car and
 * Limousine apps (table `rental_customers`; {@see \Modules\Limousine\Models\LimoCustomer}
 * is the same table under the limousine app's own menu entry). Carries the
 * identity fields a rental desk needs (CPR/ID, driving licence, nationality).
 *
 * The {@see getServiceTagAttribute() service tag} is DERIVED from actual usage —
 * a customer reads as Rental, Limousine or Both depending on whether they have
 * rental orders and/or limousine bookings — so customer service sees what each
 * customer uses with no field to maintain. Cross-app reads are guarded by
 * `Schema::hasTable()` so the rental app still works without limousine installed.
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $cpr
 * @property string|null $license_no
 * @property string|null $nationality
 * @property string|null $address
 * @property bool $active
 */
final class RentalCustomer extends Model implements DefinesIrModel
{
    use DerivesServiceTag;

    protected $table = 'rental_customers';

    /** @var list<string> */
    protected $fillable = [
        'name', 'phone', 'email', 'cpr', 'license_no', 'nationality', 'address', 'active',
    ];

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
            model: 'rental.customer',
            name: 'Customer',
            class: self::class,
            table: 'rental_customers',
            module: 'rental',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('phone', 'Phone', 'char', sequence: 20),
                new FieldDefinition('email', 'Email', 'char', sequence: 30),
                new FieldDefinition('cpr', 'CPR / ID', 'char', sequence: 40),
                new FieldDefinition('license_no', 'Licence no.', 'char', sequence: 50),
                new FieldDefinition('nationality', 'Nationality', 'char', sequence: 60),
                new FieldDefinition('address', 'Address', 'text', sequence: 70),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 80),
            ],
            views: [
                new ViewDefinition('Customers', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'phone', 'label' => 'Phone'],
                        ['field' => 'cpr', 'label' => 'CPR / ID'],
                        // Derived Rental / Limousine / Both badge (accessor, no DB sort).
                        ['field' => 'service_tag_label', 'label' => 'Service'],
                        ['field' => 'nationality', 'label' => 'Nationality', 'sortable' => true],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'bool'],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 20,
                    'searchable' => ['name', 'phone', 'cpr', 'license_no'],
                    'open' => '/app/rental/customer/{id}',
                ]),
                new ViewDefinition('Customer', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true],
                        ['field' => 'phone', 'label' => 'Phone', 'widget' => 'tel'],
                        ['field' => 'email', 'label' => 'Email', 'widget' => 'email'],
                        ['field' => 'cpr', 'label' => 'CPR / ID', 'widget' => 'text'],
                        ['field' => 'license_no', 'label' => 'Licence no.', 'widget' => 'text'],
                        ['field' => 'nationality', 'label' => 'Nationality', 'widget' => 'text'],
                        ['field' => 'address', 'label' => 'Address', 'widget' => 'textarea'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox'],
                    ],
                ]),
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Modules\Rental\Models\Concerns\DerivesServiceTag;

/**
 * The limousine app's "Customers" master. It is the SAME shared customer store
 * as Rent A Car — both apps read and write the `rental_customers` table, so a
 * customer is entered once and seen in both apps. This model exists only to give
 * the limousine app its own menu entry + routes (the per-app menu can only list
 * models owned by that module); the records, fields and the derived service tag
 * are shared with {@see \Modules\Rental\Models\RentalCustomer}.
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $phone
 * @property string|null $country
 * @property string|null $email          General address — how the customer is known
 * @property string|null $service_email  Where trip / service notices go (companies)
 * @property string|null $cpr
 * @property string|null $cr_number
 * @property string|null $cr_document
 * @property string|null $contact_person
 * @property string|null $contact_phone
 * @property string|null $license_no
 * @property string|null $nationality
 * @property string|null $address
 * @property bool $active
 * @property-read string $flag
 */
final class LimoCustomer extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasCountryFlag;
    use DerivesServiceTag;

    /** Shared table — the single transport-customer store for both apps. */
    protected $table = 'rental_customers';

    /** @var list<string> */
    protected $fillable = [
        'name', 'type', 'phone', 'country', 'email', 'service_email', 'cpr', 'cr_number', 'cr_document',
        'contact_person', 'contact_phone', 'license_no', 'nationality', 'address', 'active',
    ];

    /** Corporate account — books on behalf of its own guests. */
    public const TYPE_COMPANY = 'company';

    public function isCompany(): bool
    {
        return $this->type === self::TYPE_COMPANY;
    }

    /**
     * Where trip notices go: the service address when one is set, otherwise the
     * general one.
     *
     * At a company the person who books the car and the person who follows the
     * trip are usually different, so a service notice sent to the booking
     * address lands with someone who won't act on it. Falling back keeps every
     * customer reachable even before a service address has been filled in.
     */
    public function serviceEmail(): ?string
    {
        $service = $this->service_email;
        if (is_string($service) && trim($service) !== '') {
            return trim($service);
        }

        $general = $this->email;

        return is_string($general) && trim($general) !== '' ? trim($general) : null;
    }

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
            model: 'limousine.customer',
            name: 'Customer',
            class: self::class,
            table: 'rental_customers',
            module: 'limousine',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('phone', 'Phone', 'char', sequence: 20),
                new FieldDefinition('email', 'General email', 'char', sequence: 30),
                new FieldDefinition('service_email', 'Service email', 'char', sequence: 32),
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
                        ['field' => 'cpr', 'label' => 'CPR / CR'],
                        // Derived Rental / Limousine / Both badge (accessor, no DB sort).
                        ['field' => 'service_tag_label', 'label' => 'Service'],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'bool'],
                        ['field' => 'flag', 'label' => 'Country'],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 20,
                    'searchable' => ['name', 'phone', 'cpr', 'cr_number', 'contact_person'],
                    'open' => '/app/limousine/customer/{id}',
                ]),
                new ViewDefinition('Customer', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true],
                        ['field' => 'phone', 'label' => 'Phone', 'widget' => 'tel'],
                        ['field' => 'email', 'label' => 'General email', 'widget' => 'email', 'help' => 'The main address for this customer — quotes, invoices, general contact.'],
                        // Companies: the booker and the person who follows the
                        // trip are rarely the same, so service notices get their
                        // own address. Blank falls back to the general one.
                        ['field' => 'service_email', 'label' => 'Service email', 'widget' => 'email', 'help' => 'Where service orders and "driver has arrived" notices go. For companies this is usually a different person from the one who books. Leave blank to use the general email.'],
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

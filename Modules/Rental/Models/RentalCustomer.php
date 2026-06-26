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
 * @property string $type
 * @property string|null $phone
 * @property string|null $country
 * @property string|null $email
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
final class RentalCustomer extends Model implements DefinesIrModel
{
    use DerivesServiceTag;

    protected $table = 'rental_customers';

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_COMPANY = 'company';

    /** @var list<string> */
    protected $fillable = [
        'name', 'type', 'phone', 'country', 'email', 'cpr', 'cr_number', 'cr_document',
        'contact_person', 'contact_phone', 'license_no', 'nationality', 'address', 'active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true, 'type' => self::TYPE_INDIVIDUAL];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function isCompany(): bool
    {
        return $this->type === self::TYPE_COMPANY;
    }

    public function typeLabel(): string
    {
        return $this->isCompany() ? 'Company' : 'Individual';
    }

    /** Country flag emoji from the ISO-2 country code (e.g. BH → 🇧🇭). */
    public function getFlagAttribute(): string
    {
        return self::flagFor($this->country);
    }

    public static function flagFor(?string $code): string
    {
        if ($code === null || strlen($code) !== 2 || ! ctype_alpha($code)) {
            return '';
        }

        $code = strtoupper($code);
        $a = mb_chr(0x1F1E6 + ord($code[0]) - ord('A'));
        $b = mb_chr(0x1F1E6 + ord($code[1]) - ord('A'));

        return ($a !== false ? $a : '') . ($b !== false ? $b : '');
    }

    /** Human country name for the stored ISO-2 code. */
    public function countryName(): ?string
    {
        foreach (self::countries() as $c) {
            if ($c['code'] === $this->country) {
                return $c['name'];
            }
        }

        return null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return [
            ['value' => self::TYPE_INDIVIDUAL, 'label' => 'Individual'],
            ['value' => self::TYPE_COMPANY, 'label' => 'Company'],
        ];
    }

    /**
     * Selectable countries with their dial code — Bahrain-first, then the GCC
     * and the most common expat origins. The flag is derived from the code.
     *
     * @return list<array{code: string, name: string, dial: string}>
     */
    public static function countries(): array
    {
        return [
            ['code' => 'BH', 'name' => 'Bahrain', 'dial' => '+973'],
            ['code' => 'SA', 'name' => 'Saudi Arabia', 'dial' => '+966'],
            ['code' => 'AE', 'name' => 'United Arab Emirates', 'dial' => '+971'],
            ['code' => 'KW', 'name' => 'Kuwait', 'dial' => '+965'],
            ['code' => 'QA', 'name' => 'Qatar', 'dial' => '+974'],
            ['code' => 'OM', 'name' => 'Oman', 'dial' => '+968'],
            ['code' => 'EG', 'name' => 'Egypt', 'dial' => '+20'],
            ['code' => 'JO', 'name' => 'Jordan', 'dial' => '+962'],
            ['code' => 'LB', 'name' => 'Lebanon', 'dial' => '+961'],
            ['code' => 'SY', 'name' => 'Syria', 'dial' => '+963'],
            ['code' => 'IQ', 'name' => 'Iraq', 'dial' => '+964'],
            ['code' => 'YE', 'name' => 'Yemen', 'dial' => '+967'],
            ['code' => 'SD', 'name' => 'Sudan', 'dial' => '+249'],
            ['code' => 'IN', 'name' => 'India', 'dial' => '+91'],
            ['code' => 'PK', 'name' => 'Pakistan', 'dial' => '+92'],
            ['code' => 'BD', 'name' => 'Bangladesh', 'dial' => '+880'],
            ['code' => 'LK', 'name' => 'Sri Lanka', 'dial' => '+94'],
            ['code' => 'NP', 'name' => 'Nepal', 'dial' => '+977'],
            ['code' => 'PH', 'name' => 'Philippines', 'dial' => '+63'],
            ['code' => 'ID', 'name' => 'Indonesia', 'dial' => '+62'],
            ['code' => 'GB', 'name' => 'United Kingdom', 'dial' => '+44'],
            ['code' => 'US', 'name' => 'United States', 'dial' => '+1'],
            ['code' => 'CA', 'name' => 'Canada', 'dial' => '+1'],
            ['code' => 'FR', 'name' => 'France', 'dial' => '+33'],
            ['code' => 'DE', 'name' => 'Germany', 'dial' => '+49'],
            ['code' => 'TR', 'name' => 'Türkiye', 'dial' => '+90'],
            ['code' => 'IR', 'name' => 'Iran', 'dial' => '+98'],
        ];
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
                        ['field' => 'cpr', 'label' => 'CPR / CR'],
                        // Derived Rental / Limousine / Both badge (accessor, no DB sort).
                        ['field' => 'service_tag_label', 'label' => 'Service'],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'bool'],
                        // Country flag (accessor) — last, per request.
                        ['field' => 'flag', 'label' => 'Country'],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 20,
                    'searchable' => ['name', 'phone', 'cpr', 'cr_number', 'license_no', 'contact_person'],
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

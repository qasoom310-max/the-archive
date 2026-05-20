<?php

declare(strict_types=1);

namespace Modules\Contacts\Models;

use App\Erp\Chatter\Chatterable;
use App\Erp\Chatter\HasChatter;
use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * A contact: a company or an individual. Odoo's `res.partner` analogue.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_company
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $image_path
 * @property string|null $street
 * @property string|null $street2
 * @property string|null $city
 * @property string|null $zip
 * @property string|null $state
 * @property string|null $country
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Partner extends Model implements Chatterable, DefinesIrModel
{
    use HasChatter;

    protected $table = 'partners';

    /** @var list<string> */
    protected $fillable = [
        'name', 'is_company', 'email', 'phone', 'image_path',
        'street', 'street2', 'city', 'zip', 'state', 'country',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_company' => 'boolean'];
    }

    public function companyType(): string
    {
        return $this->is_company ? 'Company' : 'Individual';
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'contacts.partner',
            name: 'Contact',
            class: self::class,
            table: 'partners',
            module: 'contacts',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('is_company', 'Is a Company', 'boolean', sequence: 20),
                new FieldDefinition('image_path', 'Image', 'binary', sequence: 30),
                new FieldDefinition('email', 'Email', 'char', sequence: 40),
                new FieldDefinition('phone', 'Phone', 'char', sequence: 50),
                new FieldDefinition('street', 'Street', 'char', sequence: 60),
                new FieldDefinition('street2', 'Street 2', 'char', sequence: 70),
                new FieldDefinition('city', 'City', 'char', sequence: 80),
                new FieldDefinition('zip', 'ZIP', 'char', sequence: 90),
                new FieldDefinition('state', 'State', 'char', sequence: 100),
                new FieldDefinition('country', 'Country', 'char', sequence: 110),
            ],
            views: [
                new ViewDefinition('Contacts', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'email', 'label' => 'Email', 'sortable' => true],
                        ['field' => 'phone', 'label' => 'Phone'],
                        ['field' => 'city', 'label' => 'City', 'sortable' => true],
                        ['field' => 'is_company', 'label' => 'Company', 'format' => 'bool'],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 15,
                    'open' => '/app/contacts/partner/{id}',
                ]),
                new ViewDefinition('Contacts', 'kanban', [
                    'group_by' => 'country',
                    'card' => [
                        'title' => 'name',
                        'subtitle' => 'email',
                        'badges' => ['city', 'phone'],
                    ],
                    'open' => '/app/contacts/partner/{id}',
                ]),
                new ViewDefinition('Contact', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true],
                        ['field' => 'is_company', 'label' => 'Is a Company', 'widget' => 'checkbox', 'placeholder' => 'This contact is a company'],
                        ['field' => 'image_path', 'label' => 'Image', 'widget' => 'image'],
                        ['field' => 'email', 'label' => 'Email', 'widget' => 'email'],
                        ['field' => 'phone', 'label' => 'Phone', 'widget' => 'tel'],
                        ['field' => 'street', 'label' => 'Street', 'widget' => 'text'],
                        ['field' => 'street2', 'label' => 'Street 2', 'widget' => 'text'],
                        ['field' => 'city', 'label' => 'City', 'widget' => 'text'],
                        ['field' => 'zip', 'label' => 'ZIP', 'widget' => 'text'],
                        ['field' => 'state', 'label' => 'State', 'widget' => 'text'],
                        ['field' => 'country', 'label' => 'Country', 'widget' => 'text'],
                    ],
                ]),
            ],
        );
    }
}

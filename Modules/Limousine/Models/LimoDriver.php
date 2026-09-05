<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * A driver, seen from the Limousine app.
 *
 * The SAME people drive for both apps, so this reads and writes the same table
 * as {@see \Modules\Rental\Models\Driver} — exactly how customers are shared.
 * Adding a driver in either app makes them available in the other, and a phone
 * number corrected in one is corrected everywhere; two lists would drift apart
 * the first time somebody updated a licence in only one of them.
 *
 * The separate model exists so the driver shows up under Limousine's own menu
 * with its own `ir_model` entry and ACL, rather than sending staff into Rent A
 * Car to manage someone they dispatch daily.
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
 */
final class LimoDriver extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\GuardsDeletionWhenReferenced;
    use \Modules\Rental\Models\Concerns\DriverDeletionReferences;
    use \Modules\Rental\Models\Concerns\HasDriverLicence;

    /** Shared table — the single driver store for both transport apps. */
    protected $table = 'rental_drivers';

    /** @var list<string> */
    protected $fillable = [
        'name', 'phone', 'cpr', 'cpr_doc',
        'license_no', 'license_expiry', 'license_doc',
        'nationality', 'active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'license_expiry' => 'date'];
    }

    /** Name plus the phone the office would ring, for pickers and snapshots. */
    public function displayName(): string
    {
        $phone = trim((string) ($this->phone ?? ''));

        return $phone !== '' ? $this->name . ' · ' . $phone : $this->name;
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'limousine.driver',
            name: 'Driver',
            class: self::class,
            table: 'rental_drivers',
            module: 'limousine',
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
                    'open' => '/app/limousine/driver/{id}',
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
                    ],
                ]),
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Ir;

use App\Erp\Enums\ModuleState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discoverable / installable application module.
 *
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string|null $summary
 * @property string|null $description
 * @property string $version
 * @property string|null $installed_version
 * @property string|null $author
 * @property string|null $category
 * @property string|null $icon
 * @property list<string> $depends
 * @property bool $application
 * @property bool $auto_install
 * @property ModuleState $state
 * @property int $sequence
 * @property \Illuminate\Support\Carbon|null $installed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class IrModule extends Model
{
    protected $table = 'ir_module';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'display_name',
        'summary',
        'description',
        'version',
        'installed_version',
        'author',
        'category',
        'icon',
        'depends',
        'application',
        'auto_install',
        'state',
        'sequence',
        'installed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depends' => 'array',
            'application' => 'boolean',
            'auto_install' => 'boolean',
            'state' => ModuleState::class,
            'sequence' => 'integer',
            'installed_at' => 'datetime',
        ];
    }

    /**
     * Models contributed by this module (matched on the module technical name).
     *
     * @return HasMany<IrModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(IrModel::class, 'module', 'name');
    }

    public function isInstalled(): bool
    {
        return $this->state === ModuleState::Installed;
    }
}

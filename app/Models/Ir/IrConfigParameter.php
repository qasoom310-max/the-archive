<?php

declare(strict_types=1);

namespace App\Models\Ir;

use Illuminate\Database\Eloquent\Model;

/**
 * A single dynamic configuration parameter. The raw `value` is always
 * stored as text; `SettingManager` casts it on read using `type`.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property string $type
 * @property string $group
 * @property string $label
 * @property string|null $description
 * @property int $sort
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class IrConfigParameter extends Model
{
    protected $table = 'ir_config_parameter';

    /** @var list<string> */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }
}

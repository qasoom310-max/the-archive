<?php

declare(strict_types=1);

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-model CRUD access rule. Mirrors Odoo's `ir.model.access`.
 *
 * @property int $id
 * @property string $name
 * @property string $model
 * @property int|null $group_id
 * @property bool $perm_read
 * @property bool $perm_write
 * @property bool $perm_create
 * @property bool $perm_unlink
 */
final class ModelAccess extends Model
{
    protected $table = 'ir_model_access';

    /** @var list<string> */
    protected $fillable = [
        'name', 'model', 'group_id',
        'perm_read', 'perm_write', 'perm_create', 'perm_unlink',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'perm_read' => 'boolean',
            'perm_write' => 'boolean',
            'perm_create' => 'boolean',
            'perm_unlink' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}

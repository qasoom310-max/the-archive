<?php

declare(strict_types=1);

namespace App\Models\Ir;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stored, data-driven view metadata. Mirrors Odoo's `ir.ui.view`.
 *
 * @property int $id
 * @property string $name
 * @property string|null $model
 * @property string $type           list|kanban|form|search
 * @property array<string, mixed> $arch
 * @property string|null $module
 * @property int $priority
 * @property int|null $inherit_id
 * @property string $mode           primary|extension
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class IrUiView extends Model
{
    protected $table = 'ir_ui_view';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'model',
        'type',
        'arch',
        'module',
        'priority',
        'inherit_id',
        'mode',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arch' => 'array',
            'priority' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<IrModel, $this>
     */
    public function relatedModel(): BelongsTo
    {
        return $this->belongsTo(IrModel::class, 'model', 'model');
    }

    /**
     * Parent view this one inherits/extends.
     *
     * @return BelongsTo<IrUiView, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'inherit_id');
    }

    /**
     * @return HasMany<IrUiView, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'inherit_id');
    }
}

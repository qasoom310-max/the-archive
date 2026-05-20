<?php

declare(strict_types=1);

namespace App\Models\Ir;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered system model. Mirrors Odoo's `ir.model`.
 *
 * @property int $id
 * @property string $model        Dotted technical id, e.g. "contacts.partner"
 * @property string $name         Human label
 * @property class-string $class  Eloquent FQCN
 * @property string $table
 * @property string|null $module
 * @property string|null $description
 * @property bool $is_custom
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class IrModel extends Model
{
    protected $table = 'ir_model';

    /** @var list<string> */
    protected $fillable = [
        'model',
        'name',
        'class',
        'table',
        'module',
        'description',
        'is_custom',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
        ];
    }

    /**
     * @return HasMany<IrModelField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(IrModelField::class, 'ir_model_id');
    }

    /**
     * @return HasMany<IrUiView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(IrUiView::class, 'model', 'model');
    }

    /**
     * @return BelongsTo<IrModule, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(IrModule::class, 'module', 'name');
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Ir;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered attribute / relationship of a system model.
 * Mirrors Odoo's `ir.model.fields`.
 *
 * @property int $id
 * @property int $ir_model_id
 * @property string $name
 * @property string $label
 * @property string $ttype
 * @property string|null $relation
 * @property bool $required
 * @property bool $readonly
 * @property bool $is_custom
 * @property list<array{value: string, label: string}>|null $selection
 * @property string|null $help
 * @property int $sequence
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class IrModelField extends Model
{
    protected $table = 'ir_model_fields';

    /** @var list<string> */
    protected $fillable = [
        'ir_model_id',
        'name',
        'label',
        'ttype',
        'relation',
        'required',
        'readonly',
        'is_custom',
        'selection',
        'help',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'readonly' => 'boolean',
            'is_custom' => 'boolean',
            'selection' => 'array',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IrModel, $this>
     */
    public function irModel(): BelongsTo
    {
        return $this->belongsTo(IrModel::class, 'ir_model_id');
    }

    /** Whether this is a relational field (many2one / one2many / many2many). */
    public function isRelational(): bool
    {
        return in_array($this->ttype, ['many2one', 'one2many', 'many2many'], true);
    }
}

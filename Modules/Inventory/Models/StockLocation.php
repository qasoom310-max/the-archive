<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Enums\LocationType;

/**
 * @property int $id
 * @property string $name
 * @property string|null $complete_name
 * @property int|null $parent_id
 * @property int|null $warehouse_id
 * @property LocationType $type
 * @property string|null $barcode
 * @property bool $active
 */
final class StockLocation extends Model
{
    protected $table = 'stock_locations';

    /** @var list<string> */
    protected $fillable = [
        'name', 'complete_name', 'parent_id',
        'warehouse_id', 'type', 'barcode', 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<StockLocation, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function isInternal(): bool
    {
        return $this->type->isInternal();
    }
}

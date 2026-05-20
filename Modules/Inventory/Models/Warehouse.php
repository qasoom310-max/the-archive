<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int|null $view_location_id
 * @property string|null $address
 */
final class Warehouse extends Model
{
    protected $table = 'warehouses';

    /** @var list<string> */
    protected $fillable = ['name', 'code', 'view_location_id', 'address'];

    /**
     * @return HasMany<StockLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(StockLocation::class, 'warehouse_id');
    }

    /**
     * @return HasMany<StockOperationType, $this>
     */
    public function operationTypes(): HasMany
    {
        return $this->hasMany(StockOperationType::class, 'warehouse_id');
    }
}

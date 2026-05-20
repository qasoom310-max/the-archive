<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Inventory\Enums\MoveState;

/**
 * An operation type = one Inventory Overview dashboard card
 * (Receipts / Delivery / Internal / PoS).
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $sequence_code
 * @property int|null $warehouse_id
 * @property int|null $default_source_location_id
 * @property int|null $default_dest_location_id
 * @property int $sequence
 * @property bool $active
 */
final class StockOperationType extends Model
{
    protected $table = 'stock_operation_types';

    /** @var list<string> */
    protected $fillable = [
        'name', 'code', 'sequence_code', 'warehouse_id',
        'default_source_location_id', 'default_dest_location_id',
        'sequence', 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<StockMove, $this>
     */
    public function moves(): HasMany
    {
        return $this->hasMany(StockMove::class, 'stock_operation_type_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /** Real-time: open moves still to process for this operation type. */
    public function toProcessCount(): int
    {
        return $this->moves()
            ->whereIn('state', [
                MoveState::Draft->value,
                MoveState::Confirmed->value,
                MoveState::Assigned->value,
            ])
            ->count();
    }

    /** Real-time: open moves whose scheduled date has passed. */
    public function lateCount(): int
    {
        return $this->moves()
            ->whereIn('state', [
                MoveState::Draft->value,
                MoveState::Confirmed->value,
                MoveState::Assigned->value,
            ])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<', Carbon::now())
            ->count();
    }
}

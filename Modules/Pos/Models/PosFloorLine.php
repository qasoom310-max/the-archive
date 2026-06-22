<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A divider line on a floor's plan canvas — a full-span "wall" at a grid
 * boundary that visually splits the floor into zones. Managed only from the
 * floor-plan editor (not a {@see DefinesIrModel}); add/remove via
 * {@see \Modules\Pos\Livewire\PosFloorPlan::toggleLine()}.
 *
 * @property int $id
 * @property int $pos_floor_id
 * @property string $orientation 'v' (column boundary) | 'h' (row boundary)
 * @property int $position       Grid-boundary index (1..N) the line sits on
 */
final class PosFloorLine extends Model
{
    protected $table = 'pos_floor_lines';

    /** @var list<string> */
    protected $fillable = ['pos_floor_id', 'orientation', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_floor_id' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PosFloor, $this>
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(PosFloor::class, 'pos_floor_id');
    }
}

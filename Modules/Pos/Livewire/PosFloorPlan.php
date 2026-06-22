<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosFloor;
use Modules\Pos\Models\PosFloorLine;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosTable;

/**
 * Restaurant floor plan — the table picker shown before the terminal. Floor
 * tabs across the top, then a free-position canvas: each table sits at its own
 * grid cell (`pos_x`/`pos_y`) so the layout mirrors the real room. A table card
 * shows the party size vs capacity ("2/4"), a colour by status (empty /
 * occupied / needs attention) and an order badge. Tapping a table opens the
 * terminal bound to that table's running order.
 *
 * Admins (pos.table Write) get an "Arrange" mode: drag tables across the canvas
 * and the position saves automatically ({@see moveTable}). Tables with no
 * position yet (newly added) wait in an "unplaced" tray below the canvas until
 * dragged on. A "quick sale" path keeps walk-in (table-less) selling available,
 * and an empty configuration falls straight through to it.
 */
#[Layout('components.layouts.app')]
#[Title('Floor plan')]
final class PosFloorPlan extends Component
{
    /** A table whose order has sat untouched this long reads as "needs attention". */
    private const ATTENTION_MINUTES = 20;

    /** Canvas grid: cell pitch in px and number of columns wide. Shared with the Blade/Alpine drag math. */
    public const CELL = 96;

    public const COLS = 12;

    public int $sessionId;

    public ?int $floorId = null;

    /** Whether the current user may rearrange tables (pos.table Write). */
    public bool $canEdit = false;

    /** Arrange mode: tables become draggable instead of tappable. */
    public bool $editing = false;

    public function mount(int $session): void
    {
        $pos = PosSession::query()->findOrFail($session);
        abort_unless($pos->state === SessionState::Opened, 403, 'The register is closed.');
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', Permission::Read);

        $this->sessionId = $pos->id;
        $this->canEdit = app(AccessControl::class)->allows(Auth::user(), 'pos.table', Permission::Write);

        $first = PosFloor::query()->where('active', true)
            ->orderBy('sequence')->orderBy('name')->value('id');
        $this->floorId = is_int($first) ? $first : null;
    }

    public function selectFloor(int $floorId): void
    {
        $this->floorId = $floorId;
    }

    public function toggleEditing(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $this->editing = ! $this->editing;
    }

    /**
     * Persist a table's new grid cell on its floor (drag-drop on the canvas).
     * Write-gated and scoped to the active floor so a crafted id can't move a
     * table the user isn't looking at.
     */
    public function moveTable(int $tableId, int $x, int $y): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.table', Permission::Write);

        $table = PosTable::query()
            ->where('pos_floor_id', $this->floorId)
            ->find($tableId);

        if ($table === null) {
            return;
        }

        $table->pos_x = max(0, min(self::COLS - 1, $x));
        $table->pos_y = max(0, $y);
        $table->save();
    }

    /**
     * Toggle a divider line on the active floor: click an empty gutter to add
     * a "wall", click an existing one to remove it. Write-gated and
     * floor-scoped. `$orientation` = 'v' (column boundary) | 'h' (row boundary).
     */
    public function toggleLine(string $orientation, int $position): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.table', Permission::Write);

        if (! in_array($orientation, ['v', 'h'], true) || $position < 1 || $this->floorId === null) {
            return;
        }

        $existing = PosFloorLine::query()
            ->where('pos_floor_id', $this->floorId)
            ->where('orientation', $orientation)
            ->where('position', $position)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return;
        }

        PosFloorLine::query()->create([
            'pos_floor_id' => $this->floorId,
            'orientation' => $orientation,
            'position' => $position,
        ]);
    }

    public function render(): View
    {
        $floors = PosFloor::query()->where('active', true)
            ->orderBy('sequence')->orderBy('name')->get();

        $tables = $this->floorId !== null
            ? PosTable::query()->where('active', true)->where('pos_floor_id', $this->floorId)
                ->orderBy('sequence')->orderBy('name')->get()
            : new Collection();

        // One active draft order per occupied table in THIS session.
        $orders = PosOrder::query()
            ->where('pos_session_id', $this->sessionId)
            ->where('state', OrderState::Draft)
            ->whereIn('pos_table_id', $tables->pluck('id'))
            ->get()
            ->keyBy('pos_table_id');

        $placed = [];
        $unplaced = [];
        $maxRow = 0;

        foreach ($tables as $table) {
            $order = $orders->get($table->id);
            $updatedAt = $order?->updated_at;
            $attention = $order !== null
                && $updatedAt instanceof Carbon
                && $updatedAt->lt(Carbon::now()->subMinutes(self::ATTENTION_MINUTES));

            $card = [
                'id' => $table->id,
                'name' => $table->name,
                'seats' => $table->seats,
                'shape' => $table->shape,
                'x' => $table->pos_x,
                'y' => $table->pos_y,
                'guests' => $order !== null ? (int) $order->guest_count : 0,
                'hasOrder' => $order !== null,
                'status' => $order === null ? 'empty' : ($attention ? 'attention' : 'occupied'),
            ];

            if ($table->pos_x !== null && $table->pos_y !== null) {
                $placed[] = $card;
                $maxRow = max($maxRow, $table->pos_y);
            } else {
                $unplaced[] = $card;
            }
        }

        $lines = $this->floorId !== null
            ? PosFloorLine::query()->where('pos_floor_id', $this->floorId)->get()
            : new Collection();

        return view('pos::floor-plan', [
            'sessionId' => $this->sessionId,
            'floors' => $floors,
            'floorId' => $this->floorId,
            'placed' => $placed,
            'unplaced' => $unplaced,
            'rows' => max(5, $maxRow + 2),
            'cell' => self::CELL,
            'cols' => self::COLS,
            'vLines' => $lines->where('orientation', 'v')->pluck('position')->map(fn ($p): int => (int) $p)->all(),
            'hLines' => $lines->where('orientation', 'h')->pluck('position')->map(fn ($p): int => (int) $p)->all(),
            'hasTables' => PosTable::query()->where('active', true)->exists(),
        ]);
    }
}

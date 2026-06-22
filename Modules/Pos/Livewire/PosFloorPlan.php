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

    /** Grid-cell pitch (px) — one table per cell; also the divider-boundary + bg-grid pitch. */
    public const CELL = 96;

    /** Table card size on the canvas (px). */
    public const TABLE = 84;

    /** FIXED canvas size in cells (does NOT grow as tables are placed). */
    public const GRID_COLS = 12;

    public const GRID_ROWS = 8;

    /** Upper bound on a saved coordinate (defensive). */
    private const MAX_POS = 8000;

    public int $sessionId;

    public ?int $floorId = null;

    /** Whether the current user may rearrange tables (pos.table Write). */
    public bool $canEdit = false;

    /** Arrange mode: pick a table, then click a square's circle to place it. */
    public bool $editing = false;

    /** Table currently picked up for placement (null = none). */
    public ?int $selectedId = null;

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
        $this->selectedId = null;
    }

    /** Centring offset that seats an 84px table inside a 96px cell. */
    private function cellOffset(): int
    {
        return intdiv(self::CELL - self::TABLE, 2);
    }

    /**
     * Pick up / put down a table for placement (click the card in arrange
     * mode). Write-gated; clicking the same table again cancels.
     */
    public function selectTable(int $tableId): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.table', Permission::Write);

        $this->selectedId = $this->selectedId === $tableId ? null : $tableId;
    }

    public function clearSelection(): void
    {
        $this->selectedId = null;
    }

    /**
     * Take a table off the plan (double-click) — clears its position so it
     * returns to the "unplaced" tray. Write-gated and floor-scoped.
     */
    public function unplaceTable(int $tableId): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.table', Permission::Write);

        $table = PosTable::query()
            ->where('pos_floor_id', $this->floorId)
            ->find($tableId);

        if ($table === null) {
            return;
        }

        $table->pos_x = null;
        $table->pos_y = null;
        $table->save();

        if ($this->selectedId === $tableId) {
            $this->selectedId = null;
        }
    }

    /**
     * Place the picked-up table onto grid cell (col, row) — the circle the
     * user clicked. Seats it centred in the cell, then clears the selection.
     */
    public function placeAt(int $col, int $row): void
    {
        if ($this->selectedId === null) {
            return;
        }

        $offset = $this->cellOffset();
        $this->moveTable($this->selectedId, $col * self::CELL + $offset, $row * self::CELL + $offset);
        $this->selectedId = null;
    }

    /**
     * Persist a table's new pixel position on its floor. Write-gated and
     * scoped to the active floor so a crafted id can't move a table the user
     * isn't looking at.
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

        $table->pos_x = max(0, min(self::MAX_POS, $x));
        $table->pos_y = max(0, min(self::MAX_POS, $y));
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

        $offset = $this->cellOffset();
        $cols = self::GRID_COLS;
        $rows = self::GRID_ROWS;
        $placed = [];
        $unplaced = [];
        $occupied = [];

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
                'x' => 0,
                'y' => 0,
                'guests' => $order !== null ? (int) $order->guest_count : 0,
                'hasOrder' => $order !== null,
                'status' => $order === null ? 'empty' : ($attention ? 'attention' : 'occupied'),
            ];

            if ($table->pos_x === null || $table->pos_y === null) {
                $unplaced[] = $card;

                continue;
            }

            // Snap the stored position to its nearest grid cell at RENDER time
            // so a table always sits squarely in a square (robust even if the
            // stored pixels are off-grid), clamped to the FIXED grid. We do NOT
            // shuffle a table off its own cell — placing one table never moves
            // another (placement onto an occupied cell is already blocked by
            // hiding that cell's circle).
            $col = max(0, min($cols - 1, (int) round(((int) $table->pos_x - $offset) / self::CELL)));
            $row = max(0, min($rows - 1, (int) round(((int) $table->pos_y - $offset) / self::CELL)));
            $occupied[$col . '-' . $row] = true;

            $card['x'] = $col * self::CELL + $offset;
            $card['y'] = $row * self::CELL + $offset;
            $placed[] = $card;
        }

        // FIXED canvas — does not grow/shrink as tables are placed, so the plan
        // never reflows or scroll-jumps mid-arrange.
        $width = $cols * self::CELL;
        $height = $rows * self::CELL;

        $lines = $this->floorId !== null
            ? PosFloorLine::query()->where('pos_floor_id', $this->floorId)->get()
            : new Collection();

        return view('pos::floor-plan', [
            'sessionId' => $this->sessionId,
            'floors' => $floors,
            'floorId' => $this->floorId,
            'placed' => $placed,
            'unplaced' => $unplaced,
            'selectedId' => $this->selectedId,
            'width' => $width,
            'height' => $height,
            'cols' => $cols,
            'rows' => $rows,
            'cell' => self::CELL,
            'offset' => $offset,
            'table' => self::TABLE,
            'occupied' => $occupied,
            'vLines' => $lines->where('orientation', 'v')->pluck('position')->map(fn ($p): int => (int) $p)->all(),
            'hLines' => $lines->where('orientation', 'h')->pluck('position')->map(fn ($p): int => (int) $p)->all(),
            'hasTables' => PosTable::query()->where('active', true)->exists(),
        ]);
    }
}

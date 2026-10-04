<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\PrepStatus;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosFloor;
use Modules\Pos\Models\PosFloorLine;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosTable;
use Livewire\Attributes\Locked;

/**
 * Restaurant floor plan — the table picker shown before the terminal. Floor
 * tabs across the top, then a free-position canvas: each table sits at its own
 * grid cell (`pos_x`/`pos_y`) so the layout mirrors the real room. A table card
 * is coloured by its order's KITCHEN status — white (free), red (sent, the cook
 * hasn't started), yellow (preparing) or green (ready / no kitchen work,
 * awaiting payment) — plus an order badge. Tapping a table opens the terminal
 * bound to that table's running order.
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
    /** Grid-cell pitch (px) — one table per cell; also the divider-boundary + bg-grid pitch. */
    public const CELL = 96;

    /** Table card size on the canvas (px). */
    public const TABLE = 84;

    /** FIXED canvas size in cells (does NOT grow as tables are placed). */
    public const GRID_COLS = 12;

    public const GRID_ROWS = 5;

    /** Upper bound on a saved coordinate (defensive). */
    private const MAX_POS = 8000;

    public int $sessionId;

    #[Locked]
    public ?int $floorId = null;

    /** Whether the current user may rearrange tables (pos.table Write). */
    public bool $canEdit = false;

    /** Arrange mode: pick a table, then click a square's circle to place it. */
    public bool $editing = false;

    /** Table currently picked up for placement (null = none). */
    #[Locked]
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

    /** The "Unpaid orders" tab: every open order with items, across all floors. */
    public bool $showUnpaid = false;

    public function selectFloor(int $floorId): void
    {
        $this->floorId = $floorId;
        $this->showUnpaid = false;
    }

    /** The name typed for a new pay-later order ("Abu Ali", "Outside bench"). */
    public string $newOrderName = '';

    /**
     * Open a pay-later order under a NAME instead of a table and go to the
     * register for it. An open one already carrying that name is reopened
     * rather than doubled — the cashier typing "Abu Ali" twice means the same
     * customer's tab.
     */
    public function openNamedOrder(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', Permission::Create);
        $session = PosSession::query()->findOrFail($this->sessionId);
        abort_unless($session->state === SessionState::Opened, 403, 'The register is closed.');

        $this->newOrderName = trim(preg_replace('/\s+/u', ' ', $this->newOrderName) ?? '');
        $this->validate(
            ['newOrderName' => ['required', 'string', 'max:80']],
            [],
            ['newOrderName' => __('Name')],
        );

        $existing = PosOrder::query()
            ->where('pos_session_id', $this->sessionId)
            ->where('state', OrderState::Draft)
            ->whereNotNull('tab_name')
            ->get(['id', 'tab_name'])
            ->first(fn (PosOrder $o): bool => mb_strtolower((string) $o->tab_name) === mb_strtolower($this->newOrderName));

        $order = $existing ?? PosOrder::openDraft($this->sessionId, [
            'tab_name' => $this->newOrderName,
            'user_id' => Auth::id(),
        ]);

        $this->redirect(url('/app/pos/session/' . $this->sessionId . '/order/' . $order->id), navigate: true);
    }

    /** Drop a named pay-later order that never got an item — nothing to pay. */
    public function discardNamedOrder(int $orderId): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', Permission::Write);

        $order = PosOrder::query()
            ->whereKey($orderId)
            ->where('pos_session_id', $this->sessionId)
            ->where('state', OrderState::Draft)
            ->whereNotNull('tab_name')
            ->whereDoesntHave('lines')
            ->first();

        if ($order !== null) {
            $order->state = OrderState::Cancelled;
            $order->save();
        }
    }

    public function showUnpaidOrders(): void
    {
        $this->showUnpaid = true;
        $this->editing = false;
        $this->selectedId = null;
    }

    /**
     * Every order in this session still waiting to be paid: a draft with at
     * least one item, on any table or none, oldest first — named by its table
     * and floor so the cashier can find it without walking the floors. A named
     * pay-later order is listed even before its first item, or it would vanish
     * the moment it was opened.
     *
     * @return list<array{id: int, reference: string, table: ?string, floor: ?string, named: bool, url: string, items: float, total: float, status: string, since: ?\Illuminate\Support\Carbon}>
     */
    private function unpaidOrders(): array
    {
        $orders = PosOrder::query()
            ->where('pos_session_id', $this->sessionId)
            ->where('state', OrderState::Draft)
            ->where(fn ($q) => $q->whereHas('lines')->orWhereNotNull('tab_name'))
            ->with('lines:id,pos_order_id,qty,prep_status')
            ->orderBy('created_at')->orderBy('id')
            ->get();

        // Tables by id (not $order->table — that name collides with Eloquent's $table).
        $tables = PosTable::query()->whereIn('id', $orders->pluck('pos_table_id')->filter())->get()->keyBy('id');
        $floors = PosFloor::query()->whereIn('id', $tables->pluck('pos_floor_id')->filter())->get()->keyBy('id');

        $rows = [];
        foreach ($orders as $order) {
            $table = $order->pos_table_id !== null ? $tables->get($order->pos_table_id) : null;
            $floor = $table !== null && $table->pos_floor_id !== null ? $floors->get($table->pos_floor_id) : null;

            $rows[] = [
                'id' => (int) $order->id,
                'reference' => (string) $order->reference,
                'table' => $order->tab_name !== null
                    ? (string) $order->tab_name
                    : ($table !== null ? self::tableLabel((string) $table->name) : null),
                'floor' => $floor !== null ? (string) $floor->name : null,
                'named' => $order->tab_name !== null,
                'url' => match (true) {
                    $order->tab_name !== null => url('/app/pos/session/' . $this->sessionId . '/order/' . $order->id),
                    $table !== null => url('/app/pos/session/' . $this->sessionId . '/table/' . $table->id),
                    default => url('/app/pos/session/' . $this->sessionId . '/terminal'),
                },
                'items' => (float) $order->lines->sum('qty'),
                'total' => (float) $order->total,
                'status' => $this->kitchenStatus($order),
                'since' => $order->created_at,
            ];
        }

        return $rows;
    }

    /** "5" reads as "Table 5"; a table already given a name ("Majlis") keeps it. */
    private static function tableLabel(string $name): string
    {
        return preg_match('/^\d+$/', trim($name)) === 1
            ? __('Table :name', ['name' => trim($name)])
            : $name;
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

    /**
     * Map a table's running order to a colour bucket from its KITCHEN state
     * (the least-progressed routed line wins, mirroring the KDS ticket rollup):
     *
     *   pending   → red    — sent, the cook hasn't started.
     *   preparing → yellow — under preparation at a station.
     *   ready     → green  — kitchen finished, OR the order has no kitchen
     *                        items at all; either way it's just awaiting payment.
     *
     * An empty table (no order) is handled by the caller as 'empty' (white).
     */
    private function kitchenStatus(PosOrder $order): string
    {
        $statuses = $order->lines->pluck('prep_status')->filter();

        if ($statuses->contains(PrepStatus::Pending)) {
            return 'pending';
        }

        if ($statuses->contains(PrepStatus::Preparing)) {
            return 'preparing';
        }

        return 'ready';
    }

    public function render(): View
    {
        $floors = PosFloor::query()->where('active', true)
            ->orderBy('sequence')->orderBy('name')->get();

        $tables = $this->floorId !== null
            ? PosTable::query()->where('active', true)->where('pos_floor_id', $this->floorId)
                ->orderBy('sequence')->orderBy('name')->get()
            : new Collection();

        // One active draft order per occupied table in THIS session. Eager-load
        // each order's line prep-statuses — they drive the table colour.
        $orders = PosOrder::query()
            ->where('pos_session_id', $this->sessionId)
            ->where('state', OrderState::Draft)
            ->whereIn('pos_table_id', $tables->pluck('id'))
            ->with('lines:id,pos_order_id,prep_status')
            ->get()
            ->keyBy('pos_table_id');

        $offset = $this->cellOffset();
        $cols = self::GRID_COLS;
        $rows = self::GRID_ROWS;

        // Map of grid cell "col-row" => table card. Each placed table occupies
        // exactly one cell of a FIXED CSS grid (rendered as a real grid in the
        // view), so a table can NEVER escape the canvas or land between cells.
        $cells = [];
        $unplaced = [];

        foreach ($tables as $table) {
            $order = $orders->get($table->id);

            // A table is only "occupied" when its draft actually has items.
            // An EMPTY draft (e.g. left behind after the previous order was
            // paid and a fresh one was opened) reads as free/white, not green —
            // otherwise a paid table never visibly clears.
            $status = 'empty';
            if ($order !== null && $order->lines->isNotEmpty()) {
                $status = $this->kitchenStatus($order);
            }

            $card = [
                'id' => $table->id,
                'name' => $table->name,
                'shape' => $table->shape,
                'hasOrder' => $status !== 'empty',
                'status' => $status,
            ];

            if ($table->pos_x === null || $table->pos_y === null) {
                $unplaced[] = $card;

                continue;
            }

            // Snap the stored pixels to a grid cell, clamped to the fixed grid.
            $col = max(0, min($cols - 1, (int) round(((int) $table->pos_x - $offset) / self::CELL)));
            $row = max(0, min($rows - 1, (int) round(((int) $table->pos_y - $offset) / self::CELL)));

            // If that cell is already taken (only happens with old overlapping
            // data — placement hides occupied cells), fall to the next free
            // cell so every table stays visible and in-grid. Normal distinct
            // placements never collide, so a neighbour is never shuffled.
            $start = $row * $cols + $col;
            for ($i = $start; $i < $cols * $rows; $i++) {
                $key = ($i % $cols) . '-' . intdiv($i, $cols);
                if (! isset($cells[$key])) {
                    $cells[$key] = $card;

                    break;
                }
            }
        }

        // FIXED canvas — does not grow/shrink as tables are placed.
        $width = $cols * self::CELL;
        $height = $rows * self::CELL;

        $lines = $this->floorId !== null
            ? PosFloorLine::query()->where('pos_floor_id', $this->floorId)->get()
            : new Collection();

        return view('pos::floor-plan', [
            'sessionId' => $this->sessionId,
            'floors' => $floors,
            'floorId' => $this->floorId,
            'cells' => $cells,
            'unplaced' => $unplaced,
            'selectedId' => $this->selectedId,
            'width' => $width,
            'height' => $height,
            'cols' => $cols,
            'rows' => $rows,
            'cell' => self::CELL,
            'table' => self::TABLE,
            'vLines' => $lines->where('orientation', 'v')->pluck('position')->map(fn ($p): int => (int) $p)->all(),
            'hLines' => $lines->where('orientation', 'h')->pluck('position')->map(fn ($p): int => (int) $p)->all(),
            'hasTables' => PosTable::query()->where('active', true)->exists(),
            'showUnpaid' => $this->showUnpaid,
            'unpaid' => $this->unpaidOrders(),
            'canCreateOrder' => app(AccessControl::class)->allows(Auth::user(), 'pos.order', Permission::Create),
        ]);
    }
}

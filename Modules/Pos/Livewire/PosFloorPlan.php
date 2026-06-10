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
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosTable;

/**
 * Restaurant floor plan — the table picker shown before the terminal. Floor
 * tabs across the top, table cards below: each card shows the party size vs
 * capacity ("2/4"), a colour by status (empty / occupied / needs attention)
 * and an order badge. Tapping a table opens the terminal bound to that
 * table's running order. A "quick sale" path keeps walk-in (table-less)
 * selling available, and an empty configuration falls straight through to it.
 */
#[Layout('components.layouts.app')]
#[Title('Floor plan')]
final class PosFloorPlan extends Component
{
    /** A table whose order has sat untouched this long reads as "needs attention". */
    private const ATTENTION_MINUTES = 20;

    public int $sessionId;

    public ?int $floorId = null;

    public function mount(int $session): void
    {
        $pos = PosSession::query()->findOrFail($session);
        abort_unless($pos->state === SessionState::Opened, 403, 'The register is closed.');
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', Permission::Read);

        $this->sessionId = $pos->id;

        $first = PosFloor::query()->where('active', true)
            ->orderBy('sequence')->orderBy('name')->value('id');
        $this->floorId = is_int($first) ? $first : null;
    }

    public function selectFloor(int $floorId): void
    {
        $this->floorId = $floorId;
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

        $cards = [];
        foreach ($tables as $table) {
            $order = $orders->get($table->id);
            $updatedAt = $order?->updated_at;
            $attention = $order !== null
                && $updatedAt instanceof Carbon
                && $updatedAt->lt(Carbon::now()->subMinutes(self::ATTENTION_MINUTES));

            $cards[] = [
                'id' => $table->id,
                'name' => $table->name,
                'seats' => $table->seats,
                'shape' => $table->shape,
                'guests' => $order !== null ? (int) $order->guest_count : 0,
                'hasOrder' => $order !== null,
                'status' => $order === null ? 'empty' : ($attention ? 'attention' : 'occupied'),
            ];
        }

        return view('pos::floor-plan', [
            'sessionId' => $this->sessionId,
            'floors' => $floors,
            'floorId' => $this->floorId,
            'cards' => $cards,
            'hasTables' => PosTable::query()->where('active', true)->exists(),
        ]);
    }
}

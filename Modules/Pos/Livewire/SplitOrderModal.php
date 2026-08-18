<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Exceptions\PosOrderSplitException;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderLine;
use Modules\Pos\Models\PosTable;
use Modules\Pos\Services\PosOrderSplitter;

/**
 * The "Split order" overlay — shared by the POS terminal and the Orders
 * list. Opened by dispatching `open-split-order` with an order id; on a
 * successful split it dispatches `order-split` so the host re-renders.
 *
 * Thin shell: all the moving of lines / money lives in
 * {@see PosOrderSplitter}. This component owns only the selection state
 * and the live split summary.
 */
final class SplitOrderModal extends Component
{
    public bool $open = false;

    public ?int $orderId = null;

    /**
     * Units to move per line: line id => quantity. Bound live from the
     * per-line number inputs; clamped to 0..available.
     *
     * @var array<int|string, int|string>
     */
    public array $move = [];

    /** Destination table for the new order; '' = no table (walk-in / quick sale). */
    public string $destTable = '';

    public string $notes = '';

    public string $error = '';

    #[On('open-split-order')]
    public function openFor(int $orderId): void
    {
        // Turned off in POS → Settings: the modal must not open even if a stale
        // page still shows the button.
        if (! Features::enabled(Feature::SplitOrder)) {
            return;
        }

        $this->guard(Permission::Read);

        $order = PosOrder::query()->with('lines')->find($orderId);
        if ($order === null) {
            return;
        }

        $this->reset(['move', 'notes', 'error']);
        $this->orderId = $orderId;
        $this->destTable = $this->defaultDestination($order);
        $this->move = $order->lines->mapWithKeys(
            static fn (PosOrderLine $l): array => [(int) $l->id => 0],
        )->all();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->orderId = null;
        $this->reset(['move', 'notes', 'error']);
    }

    /**
     * Checkbox gesture: select the whole line (move every available unit)
     * or clear it.
     */
    public function toggleLine(int $lineId): void
    {
        $line = $this->lines()->firstWhere('id', $lineId);
        if ($line === null) {
            return;
        }

        $this->move[$lineId] = (int) ($this->move[$lineId] ?? 0) > 0 ? 0 : (int) $line->qty;
    }

    /**
     * Keep typed quantities in range as the cashier edits them.
     */
    public function updatedMove(mixed $value, ?string $key): void
    {
        if ($key === null) {
            return;
        }

        $line = $this->lines()->firstWhere('id', (int) $key);
        $max = $line !== null ? (int) $line->qty : 0;
        $this->move[$key] = max(0, min((int) $value, $max));
    }

    public function submit(): void
    {
        abort_unless(Features::enabled(Feature::SplitOrder), 404);

        $this->guard(Permission::Write);
        $this->error = '';

        $order = $this->orderId !== null
            ? PosOrder::query()->find($this->orderId)
            : null;

        if ($order === null) {
            $this->close();

            return;
        }

        try {
            $new = app(PosOrderSplitter::class)->split(
                $order,
                $this->move,
                $this->destTable === '' ? null : (int) $this->destTable,
                $this->notes,
            );
        } catch (PosOrderSplitException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->dispatch('order-split', orderId: $new->id);
        $this->close();
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', $permission);
    }

    /**
     * The source order's lines (empty collection when the modal is closed /
     * unbound).
     *
     * @return Collection<int, PosOrderLine>
     */
    private function lines(): Collection
    {
        if ($this->orderId === null) {
            return new Collection();
        }

        return PosOrderLine::query()
            ->where('pos_order_id', $this->orderId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Destination tables offered for the new order. A draft source can't
     * land back on its own table (you can't run two drafts on one table),
     * so it's filtered out; a walk-in (table-less) option is offered unless
     * the source itself is the walk-in lane.
     *
     * @return list<array{value: string, label: string}>
     */
    private function tableOptions(PosOrder $order): array
    {
        $isDraft = $order->state === OrderState::Draft;

        $tables = PosTable::query()
            ->with('floor')
            ->where('active', true)
            ->when($isDraft && $order->pos_table_id !== null, fn ($q) => $q->where('id', '!=', $order->pos_table_id))
            ->orderBy('sequence')->orderBy('name')
            ->get();

        $options = [];

        // Walk-in / no-table lane — valid for a paid source, or a draft
        // source that is currently seated (moving items to a quick sale).
        if (! $isDraft || $order->pos_table_id !== null) {
            $options[] = ['value' => '', 'label' => __('No table (walk-in)')];
        }

        foreach ($tables as $table) {
            $label = $table->name;
            $floorName = $table->floor?->name;
            if ($floorName !== null) {
                $label .= ' · ' . $floorName;
            }
            $options[] = ['value' => (string) $table->id, 'label' => $label];
        }

        return $options;
    }

    private function defaultDestination(PosOrder $order): string
    {
        $options = $this->tableOptions($order);

        // Prefer a real table (the common dine-in case) over the walk-in
        // lane, mirroring the SierraPOS modal's pre-selected table.
        foreach ($options as $option) {
            if ($option['value'] !== '') {
                return $option['value'];
            }
        }

        return $options[0]['value'] ?? '';
    }

    public function render(): View
    {
        $order = $this->orderId !== null
            ? PosOrder::query()->with('lines')->find($this->orderId)
            : null;

        $lines = $order !== null ? $order->lines : new Collection();

        $movedUnits = 0;
        $movedTotal = 0.0;
        $remainingUnits = 0;
        $remainingTotal = 0.0;

        $rows = [];
        foreach ($lines as $line) {
            $available = (int) $line->qty;
            $moveQty = max(0, min((int) ($this->move[$line->id] ?? 0), $available));
            // Per-unit value carries the line's discount / tax / condiments
            // so the summary money matches what actually moves.
            $perUnit = $available > 0 ? $line->total / $available : 0.0;

            $movedUnits += $moveQty;
            $movedTotal += $perUnit * $moveQty;
            $remainingUnits += $available - $moveQty;
            $remainingTotal += $perUnit * ($available - $moveQty);

            $rows[] = [
                'id' => (int) $line->id,
                'name' => $line->name,
                'available' => $available,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->total,
                'move' => $moveQty,
                'split_total' => $perUnit * $moveQty,
            ];
        }

        $splittable = $order !== null
            && in_array($order->state, [OrderState::Draft, OrderState::Done], true)
            && (int) $lines->sum(static fn (PosOrderLine $l): int => (int) $l->qty) >= 2;

        return view('pos::split-order-modal', [
            'order' => $order,
            'rows' => $rows,
            'tableOptions' => $order !== null ? $this->tableOptions($order) : [],
            'splittable' => $splittable,
            'movedUnits' => $movedUnits,
            'movedTotal' => round($movedTotal, 2),
            'remainingUnits' => $remainingUnits,
            'remainingTotal' => round($remainingTotal, 2),
        ]);
    }
}

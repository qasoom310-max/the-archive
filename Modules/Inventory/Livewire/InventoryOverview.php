<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MoveState;
use Modules\Inventory\Models\StockLocation;
use Modules\Inventory\Models\StockMove;
use Modules\Inventory\Models\StockOperationType;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Services\InventoryAccess;

/**
 * Inventory Overview — the operations dashboard. One Kanban card per
 * operation type with real-time "to process" / "late" counts, plus a
 * small KPI strip. Counts are recomputed every render (live).
 */
#[Layout('components.layouts.app')]
#[Title('Inventory')]
final class InventoryOverview extends Component
{
    public function mount(): void
    {
        abort_unless(InventoryAccess::canAccess(Auth::user()), 403);
    }

    /**
     * Distinct products currently carrying stock. The POS catalogue
     * (`pos_products.stock_on_hand`) is where staff actually set product
     * stock, so when POS is installed that's the meaningful count — the
     * decoupled `stock_quants` ledger only fills from purchase receipts /
     * transfers. Falls back to the quant ledger when POS isn't present.
     * Guarded by a table check (no hard module dependency — Inventory stays
     * standalone) the same way the core Dashboard couples to POS.
     */
    private function productsInStock(): int
    {
        if (Schema::hasTable('pos_products')) {
            return (int) DB::table('pos_products')->where('stock_on_hand', '>', 0)->count();
        }

        return StockQuant::query()->where('quantity', '>', 0)->distinct()->count('product_id');
    }

    public function render(): View
    {
        $types = StockOperationType::query()
            ->where('active', true)
            ->orderBy('sequence')
            ->orderBy('name')
            ->get();

        $cards = $types->map(static fn (StockOperationType $t): array => [
            'id' => $t->id,
            'name' => $t->name,
            'code' => $t->sequence_code,
            'toProcess' => $t->toProcessCount(),
            'late' => $t->lateCount(),
        ])->all();

        return view('inventory::overview', [
            'cards' => $cards,
            'kpis' => [
                'internalLocations' => StockLocation::query()
                    ->where('type', LocationType::Internal->value)->count(),
                'productsInStock' => $this->productsInStock(),
                'movesDone' => StockMove::query()
                    ->where('state', MoveState::Done->value)->count(),
                'openMoves' => StockMove::query()
                    ->whereIn('state', [
                        MoveState::Draft->value,
                        MoveState::Confirmed->value,
                        MoveState::Assigned->value,
                    ])->count(),
            ],
        ]);
    }
}

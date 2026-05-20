<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MoveState;
use Modules\Inventory\Models\StockLocation;
use Modules\Inventory\Models\StockMove;
use Modules\Inventory\Models\StockOperationType;
use Modules\Inventory\Models\StockQuant;

/**
 * Inventory Overview — the operations dashboard. One Kanban card per
 * operation type with real-time "to process" / "late" counts, plus a
 * small KPI strip. Counts are recomputed every render (live).
 */
#[Layout('components.layouts.app')]
#[Title('Inventory')]
final class InventoryOverview extends Component
{
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
                'productsInStock' => StockQuant::query()
                    ->where('quantity', '>', 0)->distinct()->count('product_id'),
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

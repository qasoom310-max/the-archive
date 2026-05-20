<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Inventory\Models\StockMove;
use Modules\Inventory\Models\StockOperationType;

/**
 * Pickings list. Optionally filtered to one operation type (the
 * dashboard "View All" deep-links here via ?type=). "Validate" runs
 * the atomic transfer.
 */
#[Layout('components.layouts.app')]
#[Title('Transfers')]
final class StockTransfers extends Component
{
    #[Url]
    public ?int $type = null;

    public string $flash = '';

    public function validateMove(int $moveId): void
    {
        $move = StockMove::query()->find($moveId);

        if ($move === null) {
            return;
        }

        $move->process();
        $this->flash = "{$move->reference} validated.";
    }

    public function render(): View
    {
        $moves = StockMove::query()
            ->with(['operationType', 'source', 'destination'])
            ->when($this->type !== null, fn ($q) => $q->where('stock_operation_type_id', $this->type))
            ->orderByRaw('done_at is null desc')
            ->latest('id')
            ->limit(100)
            ->get();

        return view('inventory::transfers', [
            'moves' => $moves,
            'types' => StockOperationType::query()->where('active', true)->orderBy('sequence')->get(),
            'activeType' => $this->type !== null
                ? StockOperationType::query()->find($this->type)
                : null,
        ]);
    }
}

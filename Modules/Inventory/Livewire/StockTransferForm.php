<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Inventory\Enums\MoveState;
use Modules\Inventory\Models\StockLocation;
use Modules\Inventory\Models\StockMove;
use Modules\Inventory\Models\StockOperationType;
use Modules\Inventory\Services\InventoryAccess;

/**
 * Create a new transfer (stock move). Source/destination default from
 * the chosen operation type. Created in the Ready state so it shows on
 * the dashboard and can be validated.
 */
#[Layout('components.layouts.app')]
#[Title('New transfer')]
final class StockTransferForm extends Component
{
    public ?int $operationTypeId = null;

    public ?int $productId = null;

    public string $qty = '1';

    public ?int $sourceId = null;

    public ?int $destId = null;

    public string $scheduledAt = '';

    public function mount(?int $type = null): void
    {
        abort_unless(InventoryAccess::canAccess(Auth::user()), 403);

        $this->scheduledAt = Carbon::now()->format('Y-m-d');

        if ($type !== null) {
            $this->operationTypeId = $type;
            $this->applyOperationDefaults($type);
        }
    }

    public function updatedOperationTypeId(mixed $value): void
    {
        $this->applyOperationDefaults($value === '' || $value === null ? null : (int) $value);
    }

    private function applyOperationDefaults(?int $typeId): void
    {
        $type = $typeId !== null ? StockOperationType::query()->find($typeId) : null;

        if ($type !== null) {
            $this->sourceId = $type->default_source_location_id;
            $this->destId = $type->default_dest_location_id;
        }
    }

    public function save(): void
    {
        $qty = round((float) $this->qty, 3);

        $this->validate([
            'sourceId' => ['required', 'integer'],
            'destId' => ['required', 'integer'],
        ], [
            'sourceId.required' => 'Pick a source location.',
            'destId.required' => 'Pick a destination location.',
        ]);

        if ($qty <= 0) {
            $this->addError('qty', 'Quantity must be greater than zero.');

            return;
        }

        $code = StockOperationType::query()
            ->where('id', $this->operationTypeId)
            ->value('sequence_code') ?? 'MOV';
        $seq = StockMove::query()
            ->where('stock_operation_type_id', $this->operationTypeId)
            ->count() + 1;

        // Non-admin submissions land as Draft (= "Pending approval"): they
        // sit in the list waiting for an admin to click Validate, which
        // is the moment the move actually adjusts stock. Admin creates
        // keep the prior Assigned ("Ready") state so day-to-day operations
        // don't need an extra click.
        $initialState = InventoryAccess::canApprove(Auth::user())
            ? MoveState::Assigned
            : MoveState::Draft;

        DB::transaction(function () use ($code, $seq, $qty, $initialState): void {
            StockMove::query()->create([
                'reference' => sprintf('WH/%s/%05d', $code, $seq),
                'stock_operation_type_id' => $this->operationTypeId,
                'product_id' => $this->productId,
                'product_qty' => $qty,
                'source_location_id' => $this->sourceId,
                'dest_location_id' => $this->destId,
                'state' => $initialState,
                'scheduled_at' => $this->scheduledAt !== '' ? Carbon::parse($this->scheduledAt) : null,
            ]);
        });

        $target = $this->operationTypeId !== null ? '?type=' . $this->operationTypeId : '';
        $this->redirect(url('/app/inventory/transfers' . $target), navigate: true);
    }

    public function render(): View
    {
        return view('inventory::transfer-form', [
            'types' => StockOperationType::query()->where('active', true)->orderBy('sequence')->get(),
            'locations' => StockLocation::query()->where('active', true)->orderBy('complete_name')->orderBy('name')->get(),
        ]);
    }
}

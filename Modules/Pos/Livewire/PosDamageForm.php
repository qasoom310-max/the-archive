<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosDamage;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;

/**
 * Log a damage / waste write-off. Pick any catalogue item (product, ingredient
 * or condiment) from one combobox — the value is a composite key `p:{id}`,
 * `i:{id}` or `c:{id}`, exactly like the recipe editor — enter the quantity
 * lost, a reason and an optional note. Saving snapshots the item's unit cost,
 * creates the {@see PosDamage} (which decrements that item's stock) and returns
 * to the Damage Report.
 */
#[Layout('components.layouts.app')]
#[Title('Log damage')]
final class PosDamageForm extends Component
{
    /** Composite key of the damaged item: "p:{id}" | "i:{id}" | "c:{id}". */
    public ?string $itemKey = null;

    public string $quantity = '1';

    public string $reason = 'other';

    public string $damagedOn = '';

    public string $note = '';

    public function mount(): void
    {
        $this->guard(Permission::Create);
        $this->damagedOn = Carbon::now()->toDateString();
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.damage', $permission);
    }

    public function save(): void
    {
        $this->guard(Permission::Create);

        $this->validate([
            'itemKey' => ['required', 'string'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'in:' . implode(',', array_keys(PosDamage::REASONS))],
            'damagedOn' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        [$type, $idStr] = array_pad(explode(':', (string) $this->itemKey, 2), 2, null);
        $id = (int) $idStr;

        $resolved = $this->resolveItem((string) $type, $id);
        if ($resolved === null) {
            $this->addError('itemKey', __('Pick an item from the list.'));

            return;
        }

        [$itemType, $columns, $unitCost] = $resolved;

        PosDamage::query()->create([
            'damaged_on' => $this->damagedOn,
            'item_type' => $itemType,
            'pos_product_id' => $columns['pos_product_id'] ?? null,
            'pos_ingredient_id' => $columns['pos_ingredient_id'] ?? null,
            'pos_condiment_id' => $columns['pos_condiment_id'] ?? null,
            'quantity' => round((float) $this->quantity, 3),
            'unit_cost' => $unitCost,
            'reason' => $this->reason,
            'note' => trim($this->note) !== '' ? trim($this->note) : null,
        ]);

        session()->flash('toast', __('Damage logged.'));
        $this->redirect(url('/app/pos/damage'), navigate: true);
    }

    /**
     * Resolve the picked composite key to a concrete catalogue item: its
     * `item_type`, the FK column to set, and a unit-cost snapshot (0 for
     * condiments, which carry no tracked cost). Null when the key is malformed
     * or the item no longer exists.
     *
     * @return array{0: string, 1: array<string, int>, 2: float}|null
     */
    private function resolveItem(string $type, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return match ($type) {
            'p' => ($p = PosProduct::query()->find($id)) === null
                ? null
                : ['product', ['pos_product_id' => $id], (float) $p->cost_price],
            'i' => ($i = PosIngredient::query()->find($id)) === null
                ? null
                : ['ingredient', ['pos_ingredient_id' => $id], (float) $i->cost_price],
            'c' => PosCondiment::query()->whereKey($id)->exists()
                ? ['condiment', ['pos_condiment_id' => $id], 0.0]
                : null,
            default => null,
        };
    }

    public function render(): View
    {
        // One combobox over every stock-tracked catalogue item, each carrying a
        // composite key + its current on-hand (shown so the user sees what's
        // available before writing some off).
        $products = PosProduct::query()->orderBy('name')->get(['id', 'name', 'unit', 'stock_on_hand'])
            ->map(fn (PosProduct $p): array => [
                'key' => 'p:' . $p->id,
                'name' => (string) $p->name,
                'group' => __('Products'),
                'stock' => (float) $p->stock_on_hand,
            ]);

        $ingredients = PosIngredient::query()->orderBy('name')->get(['id', 'name', 'unit', 'stock_on_hand'])
            ->map(fn (PosIngredient $i): array => [
                'key' => 'i:' . $i->id,
                'name' => (string) $i->name,
                'group' => __('Ingredients'),
                'stock' => (float) $i->stock_on_hand,
            ]);

        $condiments = PosCondiment::query()->orderBy('name')->get(['id', 'name', 'stock_on_hand'])
            ->map(fn (PosCondiment $c): array => [
                'key' => 'c:' . $c->id,
                'name' => (string) $c->name,
                'group' => __('Condiments'),
                'stock' => (float) $c->stock_on_hand,
            ]);

        return view('pos::damage-form', [
            'items' => $products->concat($ingredients)->concat($condiments)->values(),
            'reasons' => PosDamage::REASONS,
        ]);
    }
}

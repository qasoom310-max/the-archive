<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProduction;
use Modules\Pos\Services\PosSessionManager;

/**
 * Record a production run: pick the finished product, type the ML of each raw
 * material mixed, and the system works out the bottle count (mix ÷ bottle size)
 * to add to STORE stock while deducting the materials. Perfumes POS only.
 */
#[Layout('components.layouts.app')]
#[Title('New production')]
final class ProductionForm extends Component
{
    public ?int $product_id = null;

    public string $produced_units = '';

    public string $notes = '';

    /** Set right after "Save as formula" so the view can confirm it. */
    public bool $formulaJustSaved = false;

    /** @var list<array<string, string>> */
    public array $lines = [];

    public function mount(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        $this->lines = [$this->emptyLine()];
    }

    /**
     * @return array<string, string>
     */
    private function emptyLine(): array
    {
        return ['ingredient_id' => '', 'ml_used' => ''];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        if ($this->lines === []) {
            $this->lines = [$this->emptyLine()];
        }
    }

    public function updatedProductId(): void
    {
        $this->formulaJustSaved = false;

        // Auto-fill the materials from this product's saved formula (if any).
        if ($this->product_id !== null) {
            $product = PosProduct::query()->with('formulaLines')->find($this->product_id);
            if ($product !== null && $product->formulaLines->isNotEmpty()) {
                $this->lines = $product->formulaLines->map(fn ($f): array => [
                    'ingredient_id' => (string) $f->pos_ingredient_id,
                    'ml_used' => rtrim(rtrim(number_format((float) $f->ml, 3, '.', ''), '0'), '.'),
                ])->all();
            }
        }

        // Default the produced count to the expected yield.
        $this->produced_units = $this->expectedUnits() > 0 ? (string) $this->expectedUnits() : $this->produced_units;
    }

    /** Store the current materials as this product's standard formula. */
    public function saveAsFormula(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);

        if ($this->product_id === null) {
            $this->addError('product_id', __('Pick a product first.'));

            return;
        }

        $product = PosProduct::query()->find($this->product_id);
        if ($product === null) {
            return;
        }

        $product->formulaLines()->delete();
        foreach ($this->lines as $i => $line) {
            $ingredientId = (int) ($line['ingredient_id'] ?? 0);
            $ml = (float) ($line['ml_used'] ?? 0);
            if ($ingredientId <= 0 || $ml <= 0) {
                continue;
            }
            $product->formulaLines()->create(['pos_ingredient_id' => $ingredientId, 'ml' => $ml, 'sequence' => $i]);
        }

        $this->formulaJustSaved = true;
    }

    public function totalMix(): float
    {
        $sum = 0.0;
        foreach ($this->lines as $line) {
            $sum += (float) ($line['ml_used'] ?? 0);
        }

        return round($sum, 3);
    }

    public function bottleSize(): float
    {
        return $this->product_id !== null
            ? (float) (PosProduct::query()->whereKey($this->product_id)->value('bottle_size_ml') ?? 0)
            : 0.0;
    }

    public function expectedUnits(): int
    {
        return PosProduction::expectedUnits($this->totalMix(), $this->bottleSize());
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:pos_products,id'],
            'produced_units' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required', 'integer', 'exists:pos_ingredients,id'],
            'lines.*.ml_used' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    public function save(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        $this->validate();

        if ($this->bottleSize() <= 0) {
            $this->addError('product_id', __('Set a bottle size on this product first.'));

            return;
        }

        $ingredients = PosIngredient::query()
            ->whereIn('id', array_filter(array_column($this->lines, 'ingredient_id')))
            ->get()->keyBy('id');

        $totalMix = $this->totalMix();
        $bottle = $this->bottleSize();
        $produced = (int) $this->produced_units;
        $totalCost = 0.0;
        foreach ($this->lines as $line) {
            $ing = $ingredients->get((int) $line['ingredient_id']);
            $totalCost += (float) ($line['ml_used'] ?? 0) * ($ing?->costPerMl() ?? 0);
        }

        $production = PosProduction::query()->create([
            'pos_product_id' => $this->product_id,
            'pos_session_id' => app(PosSessionManager::class)->getActiveSession()?->id,
            'bottle_size_ml' => $bottle,
            'total_mix_ml' => $totalMix,
            'expected_units' => PosProduction::expectedUnits($totalMix, $bottle),
            'produced_units' => $produced,
            'total_cost' => round($totalCost, 3),
            'unit_cost' => $produced > 0 ? round($totalCost / $produced, 4) : 0,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            'produced_by_user_id' => Auth::id(),
        ]);

        foreach ($this->lines as $line) {
            $ing = $ingredients->get((int) $line['ingredient_id']);
            $production->lines()->create([
                'pos_ingredient_id' => (int) $line['ingredient_id'],
                'ml_used' => (float) $line['ml_used'],
                'unit_cost' => $ing?->costPerMl() ?? 0, // cost per ML
            ]);
        }

        $production->load('lines.ingredient', 'product');
        $production->applyStock();

        session()->flash('toast', __('Production recorded — :n bottles added to the store.', ['n' => $produced]));
        $this->redirect('/app/pos/production', navigate: true);
    }

    public function render(): View
    {
        return view('pos::production-form', [
            'products' => PosProduct::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'bottle_size_ml']),
            'ingredients' => PosIngredient::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'unit', 'stock_on_hand', 'pack_size', 'ml_per_unit', 'cost_price']),
            'totalMix' => $this->totalMix(),
            'bottleSize' => $this->bottleSize(),
            'expected' => $this->expectedUnits(),
            'hasFormula' => $this->product_id !== null
                && PosProduct::query()->whereKey($this->product_id)->has('formulaLines')->exists(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductFormulaLine;
use Modules\Pos\Models\PosProduction;
use Modules\Pos\Models\PosProductionLine;
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
    public ?int $id = null;

    public ?int $product_id = null;

    public string $produced_units = '';

    public string $notes = '';

    public string $reference = '';

    /** Lifecycle state of the loaded run: done | draft | reversed (new = editable). */
    public string $state = PosProduction::STATE_DONE;

    /** Set right after "Save as formula" so the view can confirm it. */
    public bool $formulaJustSaved = false;

    /**
     * Liquid materials (mixed by ml).
     *
     * @var list<array<string, string>>
     */
    public array $lines = [];

    /**
     * Packaging consumed per bottle (bottle, cap, pump…).
     *
     * @var list<array<string, string>>
     */
    public array $packaging = [];

    /**
     * A production run consumes raw materials and rewrites a product's stock and
     * cost price, so it is gated on the PRODUCT permission — the same key the
     * recipe editor uses. Cashiers hold no `pos.product` grant, so this stays
     * with managers, which is the documented intent. Every mutating action
     * re-checks: Livewire runs mount() once, then dispatches straight to methods.
     */
    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', $permission);
    }

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

        abort_unless(Features::enabled(Feature::Production), 404);
        $this->guard(Permission::Read);

        if ($id !== null) {
            $production = PosProduction::query()->with('lines')->find($id);
            if ($production !== null) {
                $this->id = $production->id;
                $this->product_id = $production->pos_product_id;
                $this->produced_units = (string) $production->produced_units;
                $this->notes = $production->notes ?? '';
                $this->reference = $production->reference ?? '';
                $this->state = $production->state;

                $liquid = $production->lines->where('kind', '!=', PosProductionLine::KIND_PACKAGING);
                $pack = $production->lines->where('kind', PosProductionLine::KIND_PACKAGING);
                $this->lines = $liquid->map(fn ($l): array => [
                    'ingredient_id' => (string) $l->pos_ingredient_id,
                    'ml_used' => $this->trimNum((float) $l->ml_used),
                ])->values()->all();
                $this->packaging = $pack->map(fn ($l): array => [
                    'ingredient_id' => (string) $l->pos_ingredient_id,
                    'qty' => $this->trimNum((float) ($l->qty_per_unit ?? 0)),
                ])->values()->all();
                if ($this->lines === []) {
                    $this->lines = [$this->emptyLine()];
                }

                return;
            }
        }

        $this->lines = [$this->emptyLine()];

        // Pre-select a product when arriving from its page (?product=<id>).
        $preselect = (int) request()->query('product', 0);
        if ($preselect > 0 && PosProduct::query()->whereKey($preselect)->exists()) {
            $this->product_id = $preselect;
            $this->updatedProductId(); // auto-fills the formula + expected count
        }
    }

    /**
     * @return array<string, string>
     */
    private function emptyLine(): array
    {
        return ['ingredient_id' => '', 'ml_used' => ''];
    }

    /**
     * @return array<string, string>
     */
    private function emptyPack(): array
    {
        return ['ingredient_id' => '', 'qty' => '1'];
    }

    /** Format a number without trailing zeros for an input value. */
    private function trimNum(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
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

    public function addPackaging(): void
    {
        $this->packaging[] = $this->emptyPack();
    }

    public function removePackaging(int $index): void
    {
        unset($this->packaging[$index]);
        $this->packaging = array_values($this->packaging);
    }

    public function updatedProductId(): void
    {
        $this->formulaJustSaved = false;

        // Formula auto-fill + the expected-count default are for a NEW run only.
        // On an existing/reopened run the materials AND packaging come from the
        // run itself — replacing them from the formula would silently drop the
        // packaging the run was actually made with.
        if ($this->id !== null) {
            return;
        }

        // Auto-fill the materials from this product's saved formula (if any).
        if ($this->product_id !== null) {
            $product = PosProduct::query()->with('formulaLines')->find($this->product_id);
            if ($product !== null && $product->formulaLines->isNotEmpty()) {
                $liquid = $product->formulaLines->where('kind', '!=', PosProductFormulaLine::KIND_PACKAGING);
                $pack = $product->formulaLines->where('kind', PosProductFormulaLine::KIND_PACKAGING);
                if ($liquid->isNotEmpty()) {
                    $this->lines = $liquid->map(fn ($f): array => [
                        'ingredient_id' => (string) $f->pos_ingredient_id,
                        'ml_used' => $this->trimNum((float) $f->ml),
                    ])->values()->all();
                }
                // Only fill packaging when the formula actually declares some —
                // never WIPE manually-entered packaging for a liquid-only formula.
                if ($pack->isNotEmpty()) {
                    $this->packaging = $pack->map(fn ($f): array => [
                        'ingredient_id' => (string) $f->pos_ingredient_id,
                        'qty' => $this->trimNum((float) ($f->qty_per_unit ?? 0)),
                    ])->values()->all();
                }
            }
        }

        // Default the produced count to the expected yield.
        $this->produced_units = $this->expectedUnits() > 0 ? (string) $this->expectedUnits() : $this->produced_units;
    }

    /**
     * Delete this production, reversing its stock effect first if still applied.
     * `$force` (admin only) removes it even when its bottles have already left
     * the store — used to clean up a wrong run whose output was sold or whose
     * produced count was over-entered, so the store can never hold them all
     * again. Safe: reverseStock() clamps store stock at zero (never negative).
     */
    public function delete(bool $force = false): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        $this->guard(Permission::Write);
        if ($this->id === null) {
            return;
        }

        $production = PosProduction::query()->with('lines.ingredient', 'product')->find($this->id);
        if ($production === null) {
            return;
        }

        // Only a DONE run still has stock applied. A draft/reversed run was
        // already unwound, so reversing again would wrongly return materials.
        if ($production->isDone()) {
            if ($production->outputHasLeftStore() && ! $this->mayForce($force)) {
                $this->addError('state', __('Some bottles have already left the store (moved to the shop or sold). Return them to the store before deleting.'));

                return;
            }
            $production->reverseStock(); // materials back, bottles out of the store
        }
        $production->delete();            // lines cascade

        session()->flash('toast', __('Production deleted.'));
        $this->redirect('/app/pos/production', navigate: true);
    }

    /** Store the current materials as this product's standard formula. */
    public function saveAsFormula(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        $this->guard(Permission::Write);

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
            $product->formulaLines()->create([
                'pos_ingredient_id' => $ingredientId,
                'kind' => PosProductFormulaLine::KIND_LIQUID,
                'ml' => $ml,
                'sequence' => $i,
            ]);
        }
        foreach ($this->packaging as $i => $line) {
            $ingredientId = (int) ($line['ingredient_id'] ?? 0);
            $qty = (float) ($line['qty'] ?? 0);
            if ($ingredientId <= 0 || $qty <= 0) {
                continue;
            }
            $product->formulaLines()->create([
                'pos_ingredient_id' => $ingredientId,
                'kind' => PosProductFormulaLine::KIND_PACKAGING,
                'qty_per_unit' => $qty,
                'sequence' => 100 + $i,
            ]);
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
            'packaging' => ['array'],
            'packaging.*.ingredient_id' => ['required', 'integer', 'exists:pos_ingredients,id'],
            'packaging.*.qty' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    public function save(): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        $this->guard(Permission::Write);

        // A recorded (done) or reversed run is not editable in place — it must be
        // reopened first (which reverses its stock and turns it into a draft).
        if ($this->id !== null && $this->state !== PosProduction::STATE_DRAFT) {
            abort(403);
        }

        $this->validate();

        if ($this->bottleSize() <= 0) {
            $this->addError('product_id', __('Set a bottle size on this product first.'));

            return;
        }

        $produced = (int) $this->produced_units;

        $ingredients = PosIngredient::query()
            ->whereIn('id', array_filter(array_merge(
                array_column($this->lines, 'ingredient_id'),
                array_column($this->packaging, 'ingredient_id'),
            )))
            ->get()->keyBy('id');

        // Not-enough-stock guard. A draft's materials were already returned to
        // stock when it was reopened, so `availableMl()` already reflects them —
        // no prior-consumption add-back is needed. Liquid is compared in ML,
        // packaging in whole units (qty × bottles).
        $short = false;

        // Liquid materials (ML).
        $usedMl = [];
        foreach ($this->lines as $line) {
            $id = (int) ($line['ingredient_id'] ?? 0);
            if ($id > 0) {
                $usedMl[$id] = ($usedMl[$id] ?? 0) + (float) ($line['ml_used'] ?? 0);
            }
        }
        foreach ($usedMl as $id => $used) {
            $available = $ingredients->get($id)?->availableMl() ?? 0;
            if ($used > $available + 0.0001) {
                $short = true;
                foreach ($this->lines as $i => $line) {
                    if ((int) ($line['ingredient_id'] ?? 0) === $id) {
                        $this->addError("lines.$i.ml_used", __('Only :n ml in stock.', ['n' => rtrim(rtrim(number_format($available, 1), '0'), '.')]));
                        break;
                    }
                }
            }
        }

        // Packaging materials (whole units × bottles produced).
        $usedPack = [];
        foreach ($this->packaging as $line) {
            $id = (int) ($line['ingredient_id'] ?? 0);
            if ($id > 0) {
                $usedPack[$id] = ($usedPack[$id] ?? 0) + (float) ($line['qty'] ?? 0) * $produced;
            }
        }
        foreach ($usedPack as $id => $need) {
            $ing = $ingredients->get($id);
            $available = (float) $ing->stock_on_hand;
            if ($need > $available + 0.0001) {
                $short = true;
                foreach ($this->packaging as $i => $line) {
                    if ((int) ($line['ingredient_id'] ?? 0) === $id) {
                        $this->addError("packaging.$i.qty", __('Only :n :unit in stock.', [
                            'n' => rtrim(rtrim(number_format($available, 1), '0'), '.'),
                            'unit' => (string) $ing->unit,
                        ]));
                        break;
                    }
                }
            }
        }

        if ($short) {
            return;
        }

        $totalMix = $this->totalMix();
        $bottle = $this->bottleSize();
        $totalCost = 0.0;
        foreach ($this->lines as $line) {
            $ing = $ingredients->get((int) $line['ingredient_id']);
            $totalCost += (float) ($line['ml_used'] ?? 0) * ($ing?->costPerMl() ?? 0);
        }
        foreach ($this->packaging as $line) {
            $ing = $ingredients->get((int) $line['ingredient_id']);
            $totalCost += (float) ($line['qty'] ?? 0) * $produced * (float) $ing->cost_price;
        }

        // New run, or a reopened DRAFT being re-recorded. A draft's stock was
        // already reversed when it was reopened, so we only ever APPLY here —
        // never reverse (that would double-count).
        //
        // The run, its lines and the stock it moves are ONE operation. Without
        // that, a failure part-way through left a run marked complete with only
        // some of its materials taken — and nothing said so.
        $saved = DB::transaction(function () use ($ingredients, $produced, $bottle, $totalMix, $totalCost): ?array {
            $production = $this->id !== null
                ? PosProduction::query()->with('lines.ingredient', 'product')->find($this->id)
                : new PosProduction();
            if ($production === null) {
                return null;
            }

            $wasReopened = $production->exists;
            $production->pos_product_id = $this->product_id;
            $production->bottle_size_ml = $bottle;
            $production->total_mix_ml = $totalMix;
            $production->expected_units = PosProduction::expectedUnits($totalMix, $bottle);
            $production->produced_units = $produced;
            $production->total_cost = round($totalCost, 3);
            $production->unit_cost = $produced > 0 ? round($totalCost / $produced, 4) : 0;
            $production->state = PosProduction::STATE_DONE;
            $production->reversed_at = null;
            $production->reversed_by_user_id = null;
            $production->notes = trim($this->notes) !== '' ? trim($this->notes) : null;
            if (! $production->exists) {
                $production->pos_session_id = app(PosSessionManager::class)->getActiveSession()?->id;
                $production->produced_by_user_id = Auth::id();
            }
            $production->save();

            $production->lines()->delete();
            foreach ($this->lines as $line) {
                $ing = $ingredients->get((int) $line['ingredient_id']);
                $production->lines()->create([
                    'pos_ingredient_id' => (int) $line['ingredient_id'],
                    'kind' => PosProductionLine::KIND_LIQUID,
                    'ml_used' => (float) $line['ml_used'],
                    'unit_cost' => $ing?->costPerMl() ?? 0, // cost per ML
                ]);
            }
            foreach ($this->packaging as $line) {
                $id = (int) ($line['ingredient_id'] ?? 0);
                $qty = (float) ($line['qty'] ?? 0);
                if ($id <= 0 || $qty <= 0) {
                    continue;
                }
                $ing = $ingredients->get($id);
                $production->lines()->create([
                    'pos_ingredient_id' => $id,
                    'kind' => PosProductionLine::KIND_PACKAGING,
                    'ml_used' => 0,
                    'qty_per_unit' => $qty,
                    'unit_cost' => (float) $ing->cost_price, // cost per unit
                ]);
            }

            $production->load('lines.ingredient', 'product');
            $production->applyStock();

            return [$production, $wasReopened];
        });

        if ($saved === null) {
            return;
        }

        [$production, $wasReopened] = $saved;

        if ($wasReopened) {
            app(ActivityLogger::class)->log('production_reopened', $production->reference, __('Re-recorded after reopening.'));
        }

        session()->flash('toast', $wasReopened
            ? __('Production re-recorded.')
            : __('Production recorded — :n bottles added to the store.', ['n' => $produced]));
        $this->redirect('/app/pos/production', navigate: true);
    }

    /**
     * Reverse a completed run: undo its stock effect (materials back, bottles out
     * of the store) and lock it as REVERSED, kept for the record. Blocked when the
     * bottles have already left the store (moved to the shop or sold) — pull them
     * back first.
     */
    /**
     * Whether the "bottles left the store" lock may be overridden — only when an
     * admin explicitly asked to force it. Reversing then clamps store stock at
     * zero, so it can't go negative; it's a deliberate cleanup of a run whose
     * output can no longer be returned (sold, or an over-entered produced count).
     */
    private function mayForce(bool $force): bool
    {
        return $force && Auth::user()?->isAdmin() === true;
    }

    public function reverse(bool $force = false): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        $this->guard(Permission::Write);
        if ($this->id === null) {
            return;
        }

        $production = PosProduction::query()->with('lines.ingredient', 'product')->find($this->id);
        if ($production === null || ! $production->isDone()) {
            return;
        }

        if ($production->outputHasLeftStore() && ! $this->mayForce($force)) {
            $this->addError('state', __('Some bottles have already left the store (moved to the shop or sold). Return them to the store before reversing.'));

            return;
        }

        $production->reverse(Auth::id());
        app(ActivityLogger::class)->log('production_reversed', $production->reference, __('Reversed — materials returned, bottles removed from the store.'));

        session()->flash('toast', __('Production reversed.'));
        $this->redirect('/app/pos/production', navigate: true);
    }

    /**
     * Reopen a run for editing: a DONE run is reversed first (blocked if its
     * bottles have left the store), a REVERSED run is simply unlocked. Either way
     * it becomes an editable DRAFT with no stock applied; saving re-records it.
     */
    public function reopen(bool $force = false): void
    {
        abort_unless(Features::enabled(Feature::Production), 404);
        $this->guard(Permission::Write);
        if ($this->id === null) {
            return;
        }

        $production = PosProduction::query()->with('lines.ingredient', 'product')->find($this->id);
        if ($production === null || $production->isDraft()) {
            return;
        }

        if ($production->isDone()) {
            if ($production->outputHasLeftStore() && ! $this->mayForce($force)) {
                $this->addError('state', __('Some bottles have already left the store (moved to the shop or sold). Return them to the store before reopening.'));

                return;
            }
            $production->reverseStock(); // materials back, bottles out of the store
        }

        $production->state = PosProduction::STATE_DRAFT;
        $production->reversed_at = null;
        $production->reversed_by_user_id = null;
        $production->save();

        $this->state = PosProduction::STATE_DRAFT;
        session()->flash('toast', __('Production reopened for editing.'));
        $this->redirect('/app/pos/production/' . $production->id, navigate: true);
    }

    public function render(): View
    {
        $ingredients = PosIngredient::query()->where('active', true)->with('category')
            ->orderBy('sequence')->orderBy('name')
            ->get(['id', 'pos_ingredient_category_id', 'name', 'unit', 'stock_on_hand', 'pack_size', 'ml_per_unit', 'cost_price']);
        $ingById = $ingredients->keyBy('id');

        // Live cost preview — the same maths save() commits, so the figures the
        // cashier sees match the recorded run: liquid = ml × cost-per-ml,
        // packaging = per-bottle × bottles produced × unit cost.
        $produced = (int) ($this->produced_units === '' ? 0 : $this->produced_units);

        $materialsCost = 0.0;
        foreach ($this->lines as $line) {
            $ing = $ingById->get((int) ($line['ingredient_id'] ?? 0));
            if ($ing === null) {
                continue;
            }
            $ml = (float) (($line['ml_used'] ?? '') === '' ? 0 : $line['ml_used']);
            $materialsCost += $ml * $ing->costPerMl();
        }

        $packagingCost = 0.0;
        foreach ($this->packaging as $line) {
            $ing = $ingById->get((int) ($line['ingredient_id'] ?? 0));
            if ($ing === null) {
                continue;
            }
            $per = (float) (($line['qty'] ?? '') === '' ? 0 : $line['qty']);
            $packagingCost += $per * $produced * (float) $ing->cost_price;
        }

        $totalCost = round($materialsCost + $packagingCost, 3);

        return view('pos::production-form', [
            'products' => PosProduct::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'bottle_size_ml']),
            'ingredients' => $ingredients,
            'totalMix' => $this->totalMix(),
            'bottleSize' => $this->bottleSize(),
            'expected' => $this->expectedUnits(),
            'materialsCost' => round($materialsCost, 3),
            'packagingCost' => round($packagingCost, 3),
            'totalCost' => $totalCost,
            'unitCost' => $produced > 0 ? round($totalCost / $produced, 4) : 0.0,
            'hasFormula' => $this->product_id !== null
                && PosProduct::query()->whereKey($this->product_id)->has('formulaLines')->exists(),
            'isEditing' => $this->id !== null,
            'state' => $this->state,
            'editable' => $this->id === null || $this->state === PosProduction::STATE_DRAFT,
            // Whether this run's bottles have already left the store — blocks
            // reverse/reopen/delete of a done run (the "return them first" rule).
            'outputLeft' => $this->id !== null && $this->state === PosProduction::STATE_DONE
                && (PosProduction::query()->with('product')->find($this->id)?->outputHasLeftStore() ?? false),
            'isAdmin' => Auth::user()?->isAdmin() === true,
        ]);
    }
}

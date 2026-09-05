<?php

declare(strict_types=1);

namespace Modules\Purchases\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Livewire\Concerns\CreatesProductInline;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Purchases\Enums\PurchaseState;
use Modules\Purchases\Models\Purchase;
use Modules\Purchases\Models\PurchaseLine;
use Modules\Purchases\Services\PurchaseConfirmer;
use Throwable;
use Livewire\Attributes\Locked;

/**
 * Custom master/detail editor for a vendor bill: header (vendor, date) plus
 * repeatable product lines. While Draft it auto-saves on Save; the Confirm
 * button hands off to {@see PurchaseConfirmer}, which receives the stock and
 * posts the accounting entry. A confirmed bill is read-only.
 */
#[Layout('components.layouts.app')]
#[Title('Purchase')]
final class PurchaseForm extends Component
{
    use CreatesProductInline;

    #[Locked]
    public ?int $id = null;

    /**
     * Deep-link prefill: the Stock Report "Buy" shortcut opens
     * `/app/purchases/purchase/new?product={id}`. Read once in mount() to
     * pre-pick the product on a new bill.
     */
    #[Url(as: 'product', except: 0)]
    public int $prefillProduct = 0;

    /** @var array<string, mixed> */
    public array $form = [
        'reference' => '',
        'name' => '',
        'partner_id' => '',
        'date' => '',
        'expiry_date' => '',
        'is_stock_purchase' => true,
        'delivery_cost' => 0,
        'notes' => '',
    ];

    /** @var list<array<string, mixed>> */
    public array $lines = [];

    public string $state = 'draft';

    public ?string $reference = null;

    public bool $justConfirmed = false;

    /** Inline "new vendor" modal toggle. */
    public bool $addingVendor = false;

    /** @var array<string, string> */
    public array $newVendor = ['name' => '', 'phone' => '', 'email' => '', 'location' => ''];

    /**
     * Which line index the freshly-created product is assigned to. (The
     * inline-product modal state — $addingProduct / $newProduct — comes from
     * the shared {@see CreatesProductInline} trait.)
     */
    public ?int $productLineIndex = null;

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

        $this->id = $id;

        if ($id === null) {
            $this->form['date'] = Carbon::now()->toDateString();
            $this->lines = [$this->emptyLine()];
            $this->prefillFromProduct();

            return;
        }

        $purchase = Purchase::query()->with('lines')->findOrFail($id);

        $this->state = $purchase->state->value;
        $this->reference = $purchase->reference;
        $this->form = [
            'reference' => (string) ($purchase->reference ?? ''),
            'name' => (string) ($purchase->name ?? ''),
            'partner_id' => $purchase->partner_id ?? '',
            'date' => $purchase->date->toDateString(),
            'expiry_date' => $purchase->expiry_date?->toDateString() ?? '',
            'is_stock_purchase' => (bool) $purchase->is_stock_purchase,
            'delivery_cost' => (float) $purchase->delivery_cost,
            'notes' => (string) ($purchase->notes ?? ''),
        ];

        $this->lines = $purchase->lines
            ->map(static fn (PurchaseLine $l): array => [
                'component' => $l->pos_product_id !== null
                    ? 'p:' . $l->pos_product_id
                    : ($l->pos_condiment_id !== null
                        ? 'c:' . $l->pos_condiment_id
                        : ($l->pos_ingredient_id !== null ? 'i:' . $l->pos_ingredient_id : '')),
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unit_cost' => $l->unit_cost,
            ])
            ->all();

        if ($this->lines === []) {
            $this->lines = [$this->emptyLine()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyLine(): array
    {
        return ['component' => '', 'description' => '', 'quantity' => 1, 'unit_cost' => 0];
    }

    /**
     * Deep-link prefill: opening "New purchase" with a `?product={id}` query
     * (the Stock Report "Buy" shortcut) pre-fills the purchase name and a first
     * product line — pre-picked, with its description + recorded cost — so the
     * buyer doesn't retype the item they came to restock.
     */
    private function prefillFromProduct(): void
    {
        if ($this->prefillProduct <= 0) {
            return;
        }

        $product = PosProduct::query()->find($this->prefillProduct);

        if ($product === null) {
            return;
        }

        $name = (string) $product->name;
        $this->form['name'] = $name;
        $this->lines = [[
            'component' => 'p:' . $product->getKey(),
            'description' => $name,
            'quantity' => 1,
            'unit_cost' => (float) $product->cost_price,
        ]];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'form.partner_id' => ['nullable'],
            'form.name' => ['nullable', 'string', 'max:255'],
            'form.date' => ['required', 'date'],
            'form.expiry_date' => ['nullable', 'date'],
            'form.reference' => ['nullable', 'string', 'max:255'],
            'form.delivery_cost' => ['numeric', 'min:0'],
            'form.notes' => ['nullable', 'string'],
            'lines' => ['array'],
            'lines.*.component' => ['nullable', 'string'],
            'lines.*.quantity' => ['numeric', 'min:0'],
            'lines.*.unit_cost' => ['numeric', 'min:0'],
        ];
    }

    public function addLine(): void
    {
        if ($this->state === PurchaseState::Confirmed->value) {
            return;
        }

        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        if ($this->state === PurchaseState::Confirmed->value) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->lines = [$this->emptyLine()];
        }
    }

    /**
     * Open the inline "new vendor" modal — a quick Partner create so the buyer
     * doesn't have to leave the bill and go to Contacts.
     */
    public function openVendorModal(): void
    {
        if ($this->state === PurchaseState::Confirmed->value) {
            return;
        }

        $this->newVendor = ['name' => '', 'phone' => '', 'email' => '', 'location' => ''];
        $this->resetValidation();
        $this->addingVendor = true;
    }

    public function closeVendorModal(): void
    {
        $this->addingVendor = false;
    }

    /**
     * Persist the inline vendor as a Partner and select it on the bill. Gated
     * by the same purchase-create permission that gates the form itself (so the
     * buyer who may raise a bill may also add its vendor reference).
     */
    public function saveVendor(): void
    {
        if ($this->state === PurchaseState::Confirmed->value) {
            return;
        }

        app(AccessControl::class)->authorize(Auth::user(), 'purchases.purchase', Permission::Create);

        $name = trim((string) ($this->newVendor['name'] ?? ''));
        $phone = trim((string) ($this->newVendor['phone'] ?? ''));
        $email = trim((string) ($this->newVendor['email'] ?? ''));
        $location = trim((string) ($this->newVendor['location'] ?? ''));
        $this->newVendor = ['name' => $name, 'phone' => $phone, 'email' => $email, 'location' => $location];

        // `email` only fires when one was typed — an empty box stays optional
        // (the rule on '' would otherwise reject a blank email).
        $this->validate([
            'newVendor.name' => ['required', 'string', 'max:255'],
            'newVendor.phone' => ['nullable', 'string', 'max:50'],
            'newVendor.email' => [$email === '' ? 'nullable' : 'email', 'max:255'],
            'newVendor.location' => ['nullable', 'string', 'max:255'],
        ]);

        // Location is stored in the Partner's `city` column — the single
        // "where" field already surfaced in Contacts (list + kanban).
        $vendor = Partner::query()->create([
            'name' => $name,
            'phone' => $phone === '' ? null : $phone,
            'email' => $email === '' ? null : $email,
            'city' => $location === '' ? null : $location,
            'is_company' => true,
        ]);

        $this->form['partner_id'] = (string) $vendor->getKey();
        $this->newVendor = ['name' => '', 'phone' => '', 'email' => '', 'location' => ''];
        $this->addingVendor = false;
    }

    /**
     * Open the inline "new product" modal for a given line. The current
     * combobox search text is passed through as the suggested name so
     * "Create 'Arabica beans'" lands pre-filled.
     */
    public function openProductModal(int $index, ?string $name = null): void
    {
        if ($this->state === PurchaseState::Confirmed->value) {
            return;
        }

        $this->productLineIndex = $index;
        $this->newProduct = $this->blankProduct();
        $this->newProduct['name'] = trim((string) $name);
        $this->resetValidation();
        $this->addingProduct = true;
    }

    /**
     * Persist the inline product (shared trait) and select it on the target
     * line (prefilling its description + unit cost, like picking an existing
     * one). Gated by the same purchase-create permission as the form.
     */
    public function saveProduct(): void
    {
        if ($this->state === PurchaseState::Confirmed->value) {
            return;
        }

        app(AccessControl::class)->authorize(Auth::user(), 'purchases.purchase', Permission::Create);

        $this->newProduct['name'] = trim((string) ($this->newProduct['name'] ?? ''));
        $this->validate($this->inlineProductRules());

        $product = $this->persistInlineProduct();
        $name = (string) $product->name;
        $cost = (float) $product->cost_price;

        $index = $this->productLineIndex;
        if ($index !== null && isset($this->lines[$index])) {
            $this->lines[$index]['component'] = 'p:' . $product->getKey();
            $this->lines[$index]['description'] = $name;
            if ((float) ($this->lines[$index]['unit_cost'] ?? 0) <= 0.0) {
                $this->lines[$index]['unit_cost'] = $cost;
            }
        }

        // Tell the comboboxes (client-side Alpine list) about the new product
        // and which line now displays it.
        $this->dispatch('product-created', id: (int) $product->getKey(), name: $name, lineIndex: $index);

        $this->productLineIndex = null;
        $this->closeProductModal();
    }

    /**
     * When a line's component (product, condiment OR ingredient) is picked,
     * prefill the description; for a product or ingredient also default the
     * unit cost to its recorded cost price (condiments have no cost price —
     * left for the buyer to enter).
     */
    public function updatedLines(mixed $value, string $key): void
    {
        if (! str_ends_with($key, '.component')) {
            return;
        }

        $index = (int) explode('.', $key)[0];

        if (! isset($this->lines[$index])) {
            return;
        }

        [$type, $idStr] = array_pad(explode(':', (string) $value, 2), 2, null);
        $id = (int) $idStr;

        if ($id <= 0) {
            return;
        }

        if ($type === 'p') {
            $product = PosProduct::query()->find($id);
            if ($product === null) {
                return;
            }
            if (($this->lines[$index]['description'] ?? '') === '') {
                $this->lines[$index]['description'] = (string) $product->name;
            }
            if ((float) $this->lines[$index]['unit_cost'] <= 0.0) {
                $this->lines[$index]['unit_cost'] = (float) $product->cost_price;
            }
        } elseif ($type === 'c') {
            $condiment = PosCondiment::query()->find($id);
            if ($condiment === null) {
                return;
            }
            if (($this->lines[$index]['description'] ?? '') === '') {
                $this->lines[$index]['description'] = (string) $condiment->name;
            }
        } elseif ($type === 'i') {
            $ingredient = PosIngredient::query()->find($id);
            if ($ingredient === null) {
                return;
            }
            if (($this->lines[$index]['description'] ?? '') === '') {
                $this->lines[$index]['description'] = (string) $ingredient->name;
            }
            if ((float) $this->lines[$index]['unit_cost'] <= 0.0) {
                $this->lines[$index]['unit_cost'] = (float) $ingredient->cost_price;
            }
        }
    }

    /**
     * Live goods subtotal of the editor rows (before delivery). A plain
     * method — render() passes the value to the view.
     */
    private function goodsTotal(): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            $total += (float) ($line['quantity'] ?? 0) * (float) ($line['unit_cost'] ?? 0);
        }

        return round($total, 2);
    }

    private function deliveryCost(): float
    {
        return round((float) ($this->form['delivery_cost'] ?? 0), 2);
    }

    /** Goods + delivery — the full amount for this bill. */
    private function currentTotal(): float
    {
        return round($this->goodsTotal() + $this->deliveryCost(), 2);
    }

    /**
     * Live per-line landed unit cost (invoice unit + this line's delivery
     * share, split by value) for the editor preview. Keyed by line index.
     *
     * @return array<int, float>
     */
    private function landedPreview(): array
    {
        $delivery = $this->deliveryCost();
        $goods = $this->goodsTotal();
        $preview = [];

        foreach ($this->lines as $i => $line) {
            $qty = (float) ($line['quantity'] ?? 0);
            $unit = (float) ($line['unit_cost'] ?? 0);
            $subtotal = $qty * $unit;
            $share = ($delivery > 0.0 && $goods > 0.0) ? $delivery * ($subtotal / $goods) : 0.0;
            $perUnit = $qty > 0.0 ? $share / $qty : 0.0;
            $preview[$i] = round($unit + $perUnit, 4);
        }

        return $preview;
    }

    public function save(): void
    {
        if ($this->state === PurchaseState::Confirmed->value) {
            return;
        }

        $permission = $this->id === null ? Permission::Create : Permission::Write;
        app(AccessControl::class)->authorize(Auth::user(), 'purchases.purchase', $permission);

        $wasNew = $this->id === null;
        $this->persistRecord();

        if ($wasNew) {
            $this->redirectRoute('purchases.purchase.edit', ['id' => $this->id], navigate: true);
        }
    }

    public function confirm(): void
    {
        if ($this->state === PurchaseState::Confirmed->value) {
            return;
        }

        app(AccessControl::class)->authorize(Auth::user(), 'purchases.purchase', Permission::Write);

        $this->validate();

        $hasUsableLine = collect($this->lines)->contains(
            static fn (array $l): bool => ($l['component'] ?? '') !== '' && (float) ($l['quantity'] ?? 0) > 0,
        );

        if (! $hasUsableLine) {
            $this->addError('lines', __('Add at least one product line with a quantity before confirming.'));

            return;
        }

        $confirmed = app(PurchaseConfirmer::class)->confirm($this->persistRecord());

        $this->state = $confirmed->state->value;
        $this->reference = $confirmed->reference;
        $this->form['reference'] = (string) ($confirmed->reference ?? '');
        $this->justConfirmed = true;
    }

    /**
     * Write the header + lines (delete-and-recreate the lines — a draft bill
     * is small and this keeps the editor stateless). Returns the persisted
     * Purchase and stamps `$this->id`.
     *
     * ATOMIC, and it must stay that way. The line rewrite is a DELETE followed
     * by re-INSERTs; without a transaction the delete commits on its own, so
     * anything that threw while re-inserting (a bad value, a constraint, any
     * 500 mid-request) left the bill saved with ZERO lines — the buyer's whole
     * day of entry silently wiped, with the header still sitting there looking
     * fine. The transaction makes a failed save a no-op instead: the error
     * surfaces and the previously saved lines are still on the bill.
     */
    private function persistRecord(): Purchase
    {
        // Validate BEFORE opening the transaction — a validation error should
        // never even reach the delete.
        $this->validate();

        $previousId = $this->id;

        try {
            return DB::transaction(fn (): Purchase => $this->writeRecord());
        } catch (Throwable $e) {
            // The transaction rolled back, so a BRAND-NEW bill no longer exists.
            // Don't leave the editor pointing at an id that was never committed
            // (the next save would findOrFail on a row that isn't there).
            $this->id = $previousId;

            throw $e;
        }
    }

    /** The actual header + line write. Always called inside a transaction. */
    private function writeRecord(): Purchase
    {
        $purchase = $this->id !== null
            ? Purchase::query()->findOrFail($this->id)
            : new Purchase();

        $partnerId = ($this->form['partner_id'] === '' || $this->form['partner_id'] === null)
            ? null
            : (int) $this->form['partner_id'];

        $reference = trim((string) ($this->form['reference'] ?? ''));

        $expiry = ($this->form['expiry_date'] === '' || $this->form['expiry_date'] === null)
            ? null
            : (string) $this->form['expiry_date'];

        $purchase->fill([
            'reference' => $reference === '' ? $purchase->reference : $reference,
            'name' => ($this->form['name'] === '') ? null : (string) $this->form['name'],
            'partner_id' => $partnerId,
            'date' => (string) $this->form['date'],
            'expiry_date' => $expiry,
            'is_stock_purchase' => (bool) ($this->form['is_stock_purchase'] ?? true),
            'delivery_cost' => round((float) ($this->form['delivery_cost'] ?? 0), 2),
            'notes' => ($this->form['notes'] === '') ? null : (string) $this->form['notes'],
        ]);
        $purchase->save();

        $this->id = (int) $purchase->getKey();

        // Delete-and-recreate the lines from the editor state.
        $purchase->lines()->delete();

        foreach ($this->lines as $line) {
            // A line's component is a composite key: "p:{id}" (product),
            // "c:{id}" (condiment) or "i:{id}" (ingredient).
            [$type, $idStr] = array_pad(explode(':', (string) ($line['component'] ?? ''), 2), 2, null);
            $refId = (int) $idStr;
            $productId = ($type === 'p' && $refId > 0) ? $refId : null;
            $condimentId = ($type === 'c' && $refId > 0) ? $refId : null;
            $ingredientId = ($type === 'i' && $refId > 0) ? $refId : null;
            $quantity = (float) ($line['quantity'] ?? 0);

            // Skip rows that have neither a component nor a quantity — empty
            // editor rows shouldn't persist.
            if ($productId === null && $condimentId === null && $ingredientId === null && $quantity <= 0.0) {
                continue;
            }

            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                if ($productId !== null) {
                    $product = PosProduct::query()->find($productId);
                    $description = $product !== null ? (string) $product->name : $description;
                } elseif ($condimentId !== null) {
                    $condiment = PosCondiment::query()->find($condimentId);
                    $description = $condiment !== null ? (string) $condiment->name : $description;
                } elseif ($ingredientId !== null) {
                    $ingredient = PosIngredient::query()->find($ingredientId);
                    $description = $ingredient !== null ? (string) $ingredient->name : $description;
                }
            }

            $purchase->lines()->create([
                'pos_product_id' => $productId,
                'pos_condiment_id' => $condimentId,
                'pos_ingredient_id' => $ingredientId,
                'description' => $description,
                'quantity' => $quantity,
                'unit_cost' => (float) ($line['unit_cost'] ?? 0),
            ]);
        }

        $purchase->recomputeTotal();
        $purchase->save();

        return $purchase->fresh(['lines']) ?? $purchase;
    }

    public function render(): View
    {
        $access = app(AccessControl::class);
        $user = Auth::user();

        // The line picker offers products AND condiments AND ingredients, each
        // carrying a composite key ("p:{id}" / "c:{id}" / "i:{id}") so persist
        // knows which it is.
        //
        // Crafted products (those WITH a recipe — e.g. a sandwich) are EXCLUDED:
        // they're assembled from their components at sale time, not bought from
        // a vendor. Only recipe-less "resale" products (e.g. Pepsi) remain
        // purchasable, alongside the raw materials (ingredients) + condiments
        // that the crafted ones are actually built from.
        $components = PosProduct::query()
            ->whereDoesntHave('recipeLines')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (PosProduct $p): array => ['key' => 'p:' . $p->id, 'name' => (string) $p->name, 'type' => 'product'])
            ->concat(
                PosCondiment::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
                    ->map(static fn (PosCondiment $c): array => ['key' => 'c:' . $c->id, 'name' => (string) $c->name, 'type' => 'condiment']),
            )
            ->concat(
                PosIngredient::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
                    ->map(static fn (PosIngredient $i): array => ['key' => 'i:' . $i->id, 'name' => (string) $i->name, 'type' => 'ingredient']),
            )
            ->values();

        return view('purchases::purchase-form', [
            'vendors' => Partner::query()->orderBy('name')->get(['id', 'name']),
            'components' => $components,
            'categories' => PosCategory::query()->orderBy('name')->get(['id', 'name']),
            'unitOptions' => PosProduct::UNIT_OPTIONS,
            'goodsTotal' => $this->goodsTotal(),
            'deliveryCost' => $this->deliveryCost(),
            'total' => $this->currentTotal(),
            'landed' => $this->landedPreview(),
            'isConfirmed' => $this->state === PurchaseState::Confirmed->value,
            'canWrite' => $access->allows($user, 'purchases.purchase', Permission::Write),
            'canCreate' => $access->allows($user, 'purchases.purchase', Permission::Create),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Purchases\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Models\PosProduct;
use Modules\Purchases\Enums\PurchaseState;
use Modules\Purchases\Models\Purchase;
use Modules\Purchases\Models\PurchaseLine;
use Modules\Purchases\Services\PurchaseConfirmer;

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
    public ?int $id = null;

    /** @var array<string, mixed> */
    public array $form = [
        'reference' => '',
        'partner_id' => '',
        'date' => '',
        'is_stock_purchase' => true,
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
    public array $newVendor = ['name' => '', 'phone' => '', 'email' => ''];

    /** Inline "new product" modal toggle. */
    public bool $addingProduct = false;

    /** Which line index the freshly-created product is assigned to. */
    public ?int $productLineIndex = null;

    /** @var array<string, string> */
    public array $newProduct = ['name' => '', 'price' => '', 'cost_price' => ''];

    public function mount(?int $id = null): void
    {
        $this->id = $id;

        if ($id === null) {
            $this->form['date'] = Carbon::now()->toDateString();
            $this->lines = [$this->emptyLine()];

            return;
        }

        $purchase = Purchase::query()->with('lines')->findOrFail($id);

        $this->state = $purchase->state->value;
        $this->reference = $purchase->reference;
        $this->form = [
            'reference' => (string) ($purchase->reference ?? ''),
            'partner_id' => $purchase->partner_id ?? '',
            'date' => $purchase->date->toDateString(),
            'is_stock_purchase' => (bool) $purchase->is_stock_purchase,
            'notes' => (string) ($purchase->notes ?? ''),
        ];

        $this->lines = $purchase->lines
            ->map(static fn (PurchaseLine $l): array => [
                'pos_product_id' => $l->pos_product_id ?? '',
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
        return ['pos_product_id' => '', 'description' => '', 'quantity' => 1, 'unit_cost' => 0];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'form.partner_id' => ['nullable'],
            'form.date' => ['required', 'date'],
            'form.reference' => ['nullable', 'string', 'max:255'],
            'form.notes' => ['nullable', 'string'],
            'lines' => ['array'],
            'lines.*.pos_product_id' => ['nullable'],
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

        $this->newVendor = ['name' => '', 'phone' => '', 'email' => ''];
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
        $this->newVendor = ['name' => $name, 'phone' => $phone, 'email' => $email];

        // `email` only fires when one was typed — an empty box stays optional
        // (the rule on '' would otherwise reject a blank email).
        $this->validate([
            'newVendor.name' => ['required', 'string', 'max:255'],
            'newVendor.phone' => ['nullable', 'string', 'max:50'],
            'newVendor.email' => [$email === '' ? 'nullable' : 'email', 'max:255'],
        ]);

        $vendor = Partner::query()->create([
            'name' => $name,
            'phone' => $phone === '' ? null : $phone,
            'email' => $email === '' ? null : $email,
            'is_company' => true,
        ]);

        $this->form['partner_id'] = (string) $vendor->getKey();
        $this->newVendor = ['name' => '', 'phone' => '', 'email' => ''];
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
        $this->newProduct = ['name' => trim((string) $name), 'price' => '', 'cost_price' => ''];
        $this->resetValidation();
        $this->addingProduct = true;
    }

    public function closeProductModal(): void
    {
        $this->addingProduct = false;
        $this->productLineIndex = null;
    }

    /**
     * Persist the inline product as a PosProduct and select it on the target
     * line (prefilling its description + unit cost, like picking an existing
     * one). Gated by the same purchase-create permission as the form.
     */
    public function saveProduct(): void
    {
        if ($this->state === PurchaseState::Confirmed->value) {
            return;
        }

        app(AccessControl::class)->authorize(Auth::user(), 'purchases.purchase', Permission::Create);

        $name = trim((string) ($this->newProduct['name'] ?? ''));
        $this->newProduct['name'] = $name;

        $this->validate([
            'newProduct.name' => ['required', 'string', 'max:255'],
            'newProduct.price' => ['nullable', 'numeric', 'min:0'],
            'newProduct.cost_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $price = (float) ($this->newProduct['price'] === '' ? 0 : $this->newProduct['price']);
        $cost = (float) ($this->newProduct['cost_price'] === '' ? 0 : $this->newProduct['cost_price']);

        $product = PosProduct::query()->create([
            'name' => $name,
            'price' => $price,
            'cost_price' => $cost,
            'tax_rate' => 0,
            'active' => true,
            'stock_on_hand' => 0,
        ]);

        $index = $this->productLineIndex;
        if ($index !== null && isset($this->lines[$index])) {
            $this->lines[$index]['pos_product_id'] = (string) $product->getKey();
            $this->lines[$index]['description'] = $name;
            if ((float) ($this->lines[$index]['unit_cost'] ?? 0) <= 0.0) {
                $this->lines[$index]['unit_cost'] = $cost;
            }
        }

        // Tell the comboboxes (client-side Alpine list) about the new product
        // and which line now displays it.
        $this->dispatch('product-created', id: (int) $product->getKey(), name: $name, lineIndex: $index);

        $this->newProduct = ['name' => '', 'price' => '', 'cost_price' => ''];
        $this->addingProduct = false;
        $this->productLineIndex = null;
    }

    /**
     * When a product is picked, prefill the description and default the unit
     * cost to the product's recorded cost price (the user can still override).
     */
    public function updatedLines(mixed $value, string $key): void
    {
        if (! str_ends_with($key, '.pos_product_id')) {
            return;
        }

        $index = (int) explode('.', $key)[0];
        $productId = ($value === '' || $value === null) ? null : (int) $value;

        if ($productId === null || ! isset($this->lines[$index])) {
            return;
        }

        $product = PosProduct::query()->find($productId);

        if ($product === null) {
            return;
        }

        if (($this->lines[$index]['description'] ?? '') === '') {
            $this->lines[$index]['description'] = (string) $product->name;
        }

        $cost = (float) $this->lines[$index]['unit_cost'];
        if ($cost <= 0.0) {
            $this->lines[$index]['unit_cost'] = (float) $product->cost_price;
        }
    }

    /**
     * Live total of the editor rows (a plain method, not a Livewire computed
     * property — render() passes the value to the view).
     */
    private function currentTotal(): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            $total += (float) ($line['quantity'] ?? 0) * (float) ($line['unit_cost'] ?? 0);
        }

        return round($total, 2);
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
            static fn (array $l): bool => ($l['pos_product_id'] ?? '') !== '' && (float) ($l['quantity'] ?? 0) > 0,
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
     */
    private function persistRecord(): Purchase
    {
        $this->validate();

        $purchase = $this->id !== null
            ? Purchase::query()->findOrFail($this->id)
            : new Purchase();

        $partnerId = ($this->form['partner_id'] === '' || $this->form['partner_id'] === null)
            ? null
            : (int) $this->form['partner_id'];

        $reference = trim((string) ($this->form['reference'] ?? ''));

        $purchase->fill([
            'reference' => $reference === '' ? $purchase->reference : $reference,
            'partner_id' => $partnerId,
            'date' => (string) $this->form['date'],
            'is_stock_purchase' => (bool) ($this->form['is_stock_purchase'] ?? true),
            'notes' => ($this->form['notes'] === '') ? null : (string) $this->form['notes'],
        ]);
        $purchase->save();

        $this->id = (int) $purchase->getKey();

        // Delete-and-recreate the lines from the editor state.
        $purchase->lines()->delete();

        foreach ($this->lines as $line) {
            $productId = ($line['pos_product_id'] ?? '') === '' ? null : (int) $line['pos_product_id'];
            $quantity = (float) ($line['quantity'] ?? 0);

            // Skip rows that have neither a product nor a quantity — empty
            // editor rows shouldn't persist.
            if ($productId === null && $quantity <= 0.0) {
                continue;
            }

            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '' && $productId !== null) {
                $product = PosProduct::query()->find($productId);
                if ($product !== null) {
                    $description = (string) $product->name;
                }
            }

            $purchase->lines()->create([
                'pos_product_id' => $productId,
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

        return view('purchases::purchase-form', [
            'vendors' => Partner::query()->orderBy('name')->get(['id', 'name']),
            'products' => PosProduct::query()->orderBy('name')->get(['id', 'name', 'cost_price']),
            'total' => $this->currentTotal(),
            'isConfirmed' => $this->state === PurchaseState::Confirmed->value,
            'canWrite' => $access->allows($user, 'purchases.purchase', Permission::Write),
            'canCreate' => $access->allows($user, 'purchases.purchase', Permission::Create),
        ]);
    }
}

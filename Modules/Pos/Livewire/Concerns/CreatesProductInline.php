<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire\Concerns;

use Modules\Pos\Models\PosProduct;

/**
 * Shared inline "New product" modal state + persistence for any Livewire
 * component that wants to create a {@see PosProduct} without leaving the page
 * (the Purchases bill editor, the POS recipe editor, …). The host component
 * keeps the bits that differ — how the modal is opened and what to do with
 * the new product afterwards — and calls {@see persistInlineProduct()} once
 * it has validated {@see $newProduct} with {@see inlineProductRules()}.
 *
 * Renders via the shared `pos::partials.new-product-modal` Blade partial.
 */
trait CreatesProductInline
{
    /** Inline "new product" modal toggle. */
    public bool $addingProduct = false;

    /**
     * Full POS-product field set for the inline create modal (mirrors the
     * engine PosProduct form arch).
     *
     * @var array<string, mixed>
     */
    public array $newProduct = [
        'name' => '',
        'price' => '',
        'cost_price' => '',
        'tax_rate' => '',
        'stock_on_hand' => '',
        'unit' => 'qty',
        'reorder_point' => '',
        'barcode' => '',
        'pos_category_id' => '',
        'active' => true,
        'image_path' => '',
    ];

    public function closeProductModal(): void
    {
        $this->addingProduct = false;
        $this->newProduct = $this->blankProduct();
    }

    /**
     * @return array<string, mixed>
     */
    protected function blankProduct(): array
    {
        return [
            'name' => '',
            'price' => '',
            'cost_price' => '',
            'tax_rate' => '',
            'stock_on_hand' => '',
            'unit' => 'qty',
            'reorder_point' => '',
            'barcode' => '',
            'pos_category_id' => '',
            'active' => true,
            'image_path' => '',
        ];
    }

    /**
     * Validation rules for the inline product form. The host calls
     * `$this->validate($this->inlineProductRules())` (so the errors land in
     * the component's own error bag and render inline in the modal).
     *
     * @return array<string, array<int, string>>
     */
    protected function inlineProductRules(): array
    {
        return [
            'newProduct.name' => ['required', 'string', 'max:255'],
            'newProduct.price' => ['nullable', 'numeric', 'min:0'],
            'newProduct.cost_price' => ['nullable', 'numeric', 'min:0'],
            'newProduct.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'newProduct.stock_on_hand' => ['nullable', 'numeric', 'min:0'],
            'newProduct.reorder_point' => ['nullable', 'numeric', 'min:0'],
            'newProduct.barcode' => ['nullable', 'string', 'max:255'],
            'newProduct.unit' => ['nullable', 'string', 'max:16'],
            'newProduct.pos_category_id' => ['nullable'],
        ];
    }

    /**
     * Build + persist a PosProduct from the (already-validated) $newProduct
     * field set, applying the same defaults the POS product form uses.
     */
    protected function persistInlineProduct(): PosProduct
    {
        $name = trim((string) ($this->newProduct['name'] ?? ''));
        $num = fn (string $key): float => (float) (($this->newProduct[$key] ?? '') === '' ? 0 : $this->newProduct[$key]);

        $barcode = trim((string) ($this->newProduct['barcode'] ?? ''));
        $image = trim((string) ($this->newProduct['image_path'] ?? ''));
        $unit = ($this->newProduct['unit'] ?? '') === '' ? 'qty' : (string) $this->newProduct['unit'];
        $categoryId = ($this->newProduct['pos_category_id'] ?? '') === '' ? null : (int) $this->newProduct['pos_category_id'];
        $reorder = ($this->newProduct['reorder_point'] ?? '') === '' ? null : (float) $this->newProduct['reorder_point'];

        return PosProduct::query()->create([
            'name' => $name,
            'price' => $num('price'),
            'cost_price' => $num('cost_price'),
            'tax_rate' => $num('tax_rate'),
            'stock_on_hand' => $num('stock_on_hand'),
            'unit' => $unit,
            'reorder_point' => $reorder,
            'barcode' => $barcode === '' ? null : $barcode,
            'pos_category_id' => $categoryId,
            'active' => (bool) ($this->newProduct['active'] ?? true),
            'image_path' => $image === '' ? null : $image,
        ]);
    }
}

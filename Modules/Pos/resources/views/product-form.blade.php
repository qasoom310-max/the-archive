<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/pos/product') }}" wire:navigate class="hover:text-primary-700">{{ __('Products') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $product?->name ?? __('New product') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Pos\Models\PosProduct::class"
        model-key="pos.product"
        :record-id="$product?->id"
        title="{{ $product ? 'Edit product' : 'New product' }}"
        :key="'pos-product-form-' . ($product?->id ?? 'new')" />

    @if ($product)
        <div class="mt-6">
            @livewire(\Modules\Pos\Livewire\PosRecipeEditor::class, ['productId' => $product->id], 'recipe-' . $product->id)
        </div>
    @endif
</div>

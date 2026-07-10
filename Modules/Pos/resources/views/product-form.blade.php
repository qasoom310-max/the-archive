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
        {{-- Production shortcut — jump straight to a new batch of this product
             (perfumes POS only). --}}
        @if (\App\Erp\Business\Features::enabled(\App\Erp\Business\Feature::Production))
            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div>
                    <h2 class="text-sm font-semibold text-chrome-800">{{ __('Production') }}</h2>
                    <p class="text-xs text-chrome-400">{{ __('Mix a batch of this product into store stock.') }}</p>
                </div>
                <a href="{{ url('/app/pos/production/new?product=' . $product->id) }}" wire:navigate class="o-btn-primary text-sm">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                    {{ __('New production') }}
                </a>
            </div>
        @endif

        {{-- Secondary (gallery) images, on top of the single primary photo the
             engine form owns. Pushed to WooCommerce as additional product
             images. Always available — harmless when a database has no store. --}}
        <div class="mt-6">
            @livewire(\Modules\Pos\Livewire\PosProductGallery::class, ['productId' => $product->id], 'gallery-' . $product->id)
        </div>

        {{-- The recipe (bill of materials consumed on sale) is a café /
             kitchen concept. A retail or perfume database has no recipes,
             so its Business Type hides this editor. --}}
        @if (\App\Erp\Business\Features::enabled(\App\Erp\Business\Feature::Recipes))
            <div class="mt-6">
                @livewire(\Modules\Pos\Livewire\PosRecipeEditor::class, ['productId' => $product->id], 'recipe-' . $product->id)
            </div>
        @endif
        @if (\App\Erp\Business\Features::enabled(\App\Erp\Business\Feature::Condiments))
            <div class="mt-6">
                @livewire(\Modules\Pos\Livewire\PosProductCondiments::class, ['productId' => $product->id], 'condiments-' . $product->id)
            </div>
        @endif
    @endif
</div>

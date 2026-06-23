<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/pos') }}" wire:navigate class="hover:text-primary-700">{{ __('Point of Sale') }}</a>
        <span>/</span>
        <a href="{{ url('/app/pos/ingredient') }}" wire:navigate class="hover:text-primary-700">{{ __('Ingredients') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $ingredient?->name ?? __('New ingredient') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Pos\Models\PosIngredient::class"
        model-key="pos.ingredient"
        :record-id="$ingredient?->id"
        title="{{ $ingredient ? __('Edit ingredient') : __('New ingredient') }}"
        :key="'pos-ingredient-form-' . ($ingredient?->id ?? 'new')" />
</div>

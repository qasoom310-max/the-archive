<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/pos') }}" wire:navigate class="hover:text-primary-700">{{ __('Point of Sale') }}</a>
        <span>/</span>
        <a href="{{ url('/app/pos/condiment') }}" wire:navigate class="hover:text-primary-700">{{ __('Condiments') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $condiment?->name ?? __('New condiment') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Pos\Models\PosCondiment::class"
        model-key="pos.condiment"
        :record-id="$condiment?->id"
        title="{{ $condiment ? __('Edit condiment') : __('New condiment') }}"
        :key="'pos-condiment-form-' . ($condiment?->id ?? 'new')" />
</div>

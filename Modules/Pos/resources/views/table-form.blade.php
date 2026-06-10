<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/pos') }}" wire:navigate class="hover:text-primary-700">{{ __('Point of Sale') }}</a>
        <span>/</span>
        <a href="{{ url('/app/pos/table') }}" wire:navigate class="hover:text-primary-700">{{ __('Tables') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $table?->name ?? __('New table') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Pos\Models\PosTable::class"
        model-key="pos.table"
        :record-id="$table?->id"
        title="{{ $table ? __('Edit table') : __('New table') }}"
        :key="'pos-table-form-' . ($table?->id ?? 'new')" />
</div>

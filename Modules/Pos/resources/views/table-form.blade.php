<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-sm text-chrome-500">
            <a href="{{ url('/app/pos') }}" wire:navigate class="hover:text-primary-700">{{ __('Point of Sale') }}</a>
            <span>/</span>
            <a href="{{ url('/app/pos/table') }}" wire:navigate class="hover:text-primary-700">{{ __('Tables') }}</a>
            <span>/</span>
            <span class="font-medium text-chrome-700">{{ $table?->name ?? __('New table') }}</span>
        </div>

        @if ($canCreate && $table !== null)
            <a href="{{ url('/app/pos/table/new') }}" wire:navigate class="o-btn-primary inline-flex items-center gap-1.5 text-sm">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z" /></svg>
                {{ __('Add new') }}
            </a>
        @endif
    </div>

    <livewire:views.form-view
        :model="\Modules\Pos\Models\PosTable::class"
        model-key="pos.table"
        :record-id="$table?->id"
        title="{{ $table ? __('Edit table') : __('New table') }}"
        :key="'pos-table-form-' . ($table?->id ?? 'new')" />
</div>

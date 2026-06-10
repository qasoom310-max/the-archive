<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/pos') }}" wire:navigate class="hover:text-primary-700">{{ __('Point of Sale') }}</a>
        <span>/</span>
        <a href="{{ url('/app/pos/floor') }}" wire:navigate class="hover:text-primary-700">{{ __('Floors') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $floor?->name ?? __('New floor') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Pos\Models\PosFloor::class"
        model-key="pos.floor"
        :record-id="$floor?->id"
        title="{{ $floor ? __('Edit floor') : __('New floor') }}"
        :key="'pos-floor-form-' . ($floor?->id ?? 'new')" />
</div>

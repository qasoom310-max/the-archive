<div class="p-3 sm:p-4">
    <div class="mb-3 flex items-center justify-between">
        <h1 class="text-lg font-bold text-chrome-900">{{ $heading }}</h1>
        @if (! empty($newUrl) && $canCreate)
            <a href="{{ $newUrl }}" wire:navigate class="o-btn-primary text-sm">{{ __('New') }}</a>
        @endif
    </div>

    {{-- Reuses the Phase-4 engine List view (sort / search / bulk / column
         picker). Form views come from the same engine via the model's
         irModelDefinition(). --}}
    <livewire:views.list-view :model="$model" :model-key="$modelKey" :key="$modelKey" />
</div>

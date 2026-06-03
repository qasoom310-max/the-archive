<div class="p-3 sm:p-4">
    {{-- Reuses the Phase-4 engine List view (sort / search / bulk / column
         picker) for the given model. Form views come from the same engine
         via the model's irModelDefinition(). --}}
    <livewire:views.list-view :model="$model" :model-key="$modelKey" :key="$modelKey" />
</div>

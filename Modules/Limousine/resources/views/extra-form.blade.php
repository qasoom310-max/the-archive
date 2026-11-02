<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Trip extras')" :parent-url="url('/app/limousine/extra')" :current="$extra?->name ?? __('New extra')" />

    <livewire:views.form-view
        :model="\Modules\Limousine\Models\LimoExtra::class"
        model-key="limousine.extra"
        :record-id="$extra?->id"
        title="{{ $extra ? 'Edit extra' : 'New extra' }}"
        redirect-to="{{ url('/app/limousine/extra') }}"
        :key="'limo-extra-form-'.($extra?->id ?? 'new')" />
</div>

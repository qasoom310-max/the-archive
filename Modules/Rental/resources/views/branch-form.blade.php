<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Branches')" :parent-url="url('/app/rental/branch')" :current="$branch?->name ?? __('New branch')" />

    <livewire:views.form-view
        :model="\Modules\Rental\Models\Branch::class"
        model-key="rental.branch"
        :record-id="$branch?->id"
        title="{{ $branch ? 'Edit branch' : 'New branch' }}"
        redirect-to="{{ url('/app/rental/branch') }}"
        :key="'rental-branch-form-'.($branch?->id ?? 'new')" />
</div>

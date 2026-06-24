<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/rental/branch') }}" wire:navigate class="hover:text-primary-700">{{ __('Branches') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $branch?->name ?? __('New branch') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Rental\Models\Branch::class"
        model-key="rental.branch"
        :record-id="$branch?->id"
        title="{{ $branch ? 'Edit branch' : 'New branch' }}"
        redirect-to="{{ url('/app/rental/branch') }}"
        :key="'rental-branch-form-'.($branch?->id ?? 'new')" />
</div>

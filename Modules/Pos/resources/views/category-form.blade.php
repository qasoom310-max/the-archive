<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/pos') }}" wire:navigate class="hover:text-primary-700">Point of Sale</a>
        <span>/</span>
        <a href="{{ url('/app/pos/category') }}" wire:navigate class="hover:text-primary-700">Categories</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $category?->name ?? 'New category' }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Pos\Models\PosCategory::class"
        model-key="pos.category"
        :record-id="$category?->id"
        title="{{ $category ? 'Edit category' : 'New category' }}"
        :key="'pos-category-form-' . ($category?->id ?? 'new')" />
</div>

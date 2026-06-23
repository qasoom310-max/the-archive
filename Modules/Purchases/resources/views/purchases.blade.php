<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Purchases') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Vendor bills. Confirming one receives stock and posts to accounting automatically.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ url('/app/purchases/reorder') }}" wire:navigate
                class="inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.5 3A1.5 1.5 0 0 0 1 4.5v1A1.5 1.5 0 0 0 2.5 7H4l1.6 8.3A2 2 0 0 0 7.56 17h5.88a2 2 0 0 0 1.96-1.7L17 7H4.2l-.2-1H2.5ZM8 18a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm7 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z" clip-rule="evenodd"/></svg>
                {{ __('Reorder report') }}
            </a>
            @if ($canCreate)
                <a href="{{ url('/app/purchases/purchase/new') }}" wire:navigate class="o-btn-primary">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                    {{ __('New') }}
                </a>
            @endif
        </div>
    </div>

    <livewire:views.list-view
        :model="\Modules\Purchases\Models\Purchase::class"
        model-key="purchases.purchase"
        title="Purchases"
        :key="'purchases-list'" />
</div>

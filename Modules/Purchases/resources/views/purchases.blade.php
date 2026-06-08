<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Purchases') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Vendor bills. Confirming one receives stock and posts to accounting automatically.') }}</p>
        </div>
        @if ($canCreate)
            <a href="{{ url('/app/purchases/purchase/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New') }}
            </a>
        @endif
    </div>

    <livewire:views.list-view
        :model="\Modules\Purchases\Models\Purchase::class"
        model-key="purchases.purchase"
        title="Purchases"
        :key="'purchases-list'" />
</div>

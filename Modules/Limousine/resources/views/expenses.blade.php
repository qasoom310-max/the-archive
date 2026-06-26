<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Expenses')" :subtitle="__('Running costs.')" icon="wallet" accent="indigo">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 ring-1 ring-rose-100">
                {{ __('Total') }}: <span class="font-bold">{{ \App\Erp\Views\ValueFormat::money($total) }}</span>
            </span>
            <a href="{{ url('/app/limousine/expense/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <livewire:views.list-view
        :model="\Modules\Limousine\Models\LimoExpense::class"
        model-key="limousine.expense"
        title="Expenses"
        :key="'limo-expenses'" />
</div>

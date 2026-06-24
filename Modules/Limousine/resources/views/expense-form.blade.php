<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/limousine/expense') }}" wire:navigate class="hover:text-primary-700">{{ __('Expenses') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $expense?->reference ?? __('New expense') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Limousine\Models\LimoExpense::class"
        model-key="limousine.expense"
        :record-id="$expense?->id"
        title="{{ $expense ? 'Edit expense' : 'New expense' }}"
        redirect-to="{{ url('/app/limousine/expense') }}"
        :key="'limo-expense-form-'.($expense?->id ?? 'new')" />
</div>

<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Expenses')" :parent-url="url('/app/limousine/expense')" :current="$expense?->reference ?? __('New expense')" />

    <livewire:views.form-view
        :model="\Modules\Limousine\Models\LimoExpense::class"
        model-key="limousine.expense"
        :record-id="$expense?->id"
        title="{{ $expense ? 'Edit expense' : 'New expense' }}"
        redirect-to="{{ url('/app/limousine/expense') }}"
        :key="'limo-expense-form-'.($expense?->id ?? 'new')" />
</div>

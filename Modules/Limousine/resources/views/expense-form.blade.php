<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Expenses')" :parent-url="url('/app/limousine/expense')" :current="$expense?->reference ?? __('New expense')" />

    {{-- An expense scanned from a bill keeps the photo: one press to compare. --}}
    @if ($expense?->getAttribute('bill_path'))
        <a href="{{ url('/app/limousine/expense/' . $expense->id . '/bill') }}" target="_blank" rel="noopener" data-expense-bill
           class="mb-3 inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-primary-800 shadow-sm ring-1 ring-chrome-900/5 hover:bg-chrome-50">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22-2.219a.75.75 0 0 0-1.06 0l-1.91 1.909.47.47a.75.75 0 1 1-1.06 1.06L6.53 8.091a.75.75 0 0 0-1.06 0l-2.97 2.97ZM12 7a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z" clip-rule="evenodd"/></svg>
            {{ __('View the scanned bill') }}
        </a>
    @endif

    {{-- A bill in another currency: what the paper said, beside the BHD figures below. --}}
    @if ($expense && $expense->bill_currency !== 'BHD' && $expense->bill_amount !== null)
        <p class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" data-expense-bill-currency>
            {{ __('The bill was :amount before VAT, converted at 1 :currency = :rate BHD.', [
                'amount' => \App\Erp\Money\Currencies::format($expense->bill_amount, $expense->bill_currency),
                'currency' => $expense->bill_currency,
                'rate' => rtrim(rtrim(number_format((float) $expense->bill_rate, 6, '.', ''), '0'), '.'),
            ]) }}
        </p>
    @endif

    <livewire:views.form-view
        :model="\Modules\Limousine\Models\LimoExpense::class"
        model-key="limousine.expense"
        :record-id="$expense?->id"
        title="{{ $expense ? 'Edit expense' : 'New expense' }}"
        redirect-to="{{ url('/app/limousine/expense') }}"
        :key="'limo-expense-form-'.($expense?->id ?? 'new')" />
</div>

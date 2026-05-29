<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/accounting') }}" wire:navigate class="hover:text-primary-700">{{ __('Accounting') }}</a>
        <span>/</span>
        <a href="{{ url('/app/accounting/account') }}" wire:navigate class="hover:text-primary-700">{{ __('Chart of Accounts') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $account?->name ?? __('New account') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Accounting\Models\Account::class"
        model-key="accounting.account"
        :record-id="$account?->id"
        title="{{ $account ? __('Edit account') : __('New account') }}"
        :key="'accounting-account-form-' . ($account?->id ?? 'new')" />
</div>

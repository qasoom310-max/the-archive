<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/pos') }}" wire:navigate class="hover:text-primary-700">{{ __('Point of Sale') }}</a>
        <span>/</span>
        <a href="{{ url('/app/pos/customer_discount') }}" wire:navigate class="hover:text-primary-700">{{ __('Customer Discounts') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $discount?->label ?: $discount?->phone ?? __('New discount') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Pos\Models\PosCustomerDiscount::class"
        model-key="pos.customer_discount"
        :record-id="$discount?->id"
        title="{{ $discount ? __('Edit discount') : __('New discount') }}"
        :key="'pos-customer-discount-form-' . ($discount?->id ?? 'new')" />
</div>

<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Customer Discounts') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Assign an open discount % to a phone number. When the cashier adds that customer at the register, it comes off the order total.') }}</p>
        </div>
        @if ($canCreate)
            <a href="{{ url('/app/pos/customer_discount/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New') }}
            </a>
        @endif
    </div>

    <livewire:views.list-view
        :model="\Modules\Pos\Models\PosCustomerDiscount::class"
        model-key="pos.customer_discount"
        title="Customer Discounts"
        :key="'pos-customer-discounts-list'" />
</div>

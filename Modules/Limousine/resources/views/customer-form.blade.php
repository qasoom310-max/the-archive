<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/limousine/customer') }}" wire:navigate class="hover:text-primary-700">{{ __('Customers') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $customer?->name ?? __('New customer') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Limousine\Models\LimoCustomer::class"
        model-key="limousine.customer"
        :record-id="$customer?->id"
        title="{{ $customer ? 'Edit customer' : 'New customer' }}"
        redirect-to="{{ url('/app/limousine/customer') }}"
        :key="'limo-customer-form-'.($customer?->id ?? 'new')" />
</div>

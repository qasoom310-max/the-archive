<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Customers')" :parent-url="url('/app/limousine/customer')" :current="$customer?->name ?? __('New customer')" />

    <livewire:views.form-view
        :model="\Modules\Limousine\Models\LimoCustomer::class"
        model-key="limousine.customer"
        :record-id="$customer?->id"
        title="{{ $customer ? 'Edit customer' : 'New customer' }}"
        redirect-to="{{ url('/app/limousine/customer') }}"
        :key="'limo-customer-form-'.($customer?->id ?? 'new')" />
</div>

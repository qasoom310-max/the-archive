<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Customers')" :parent-url="url('/app/rental/customer')" :current="$customer?->name ?? __('New customer')" />

    <livewire:views.form-view
        :model="\Modules\Rental\Models\RentalCustomer::class"
        model-key="rental.customer"
        :record-id="$customer?->id"
        title="{{ $customer ? 'Edit customer' : 'New customer' }}"
        redirect-to="{{ url('/app/rental/customer') }}"
        :key="'rental-customer-form-'.($customer?->id ?? 'new')" />
</div>

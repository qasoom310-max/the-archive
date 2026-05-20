<div class="mx-auto max-w-7xl p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/contacts/partner') }}" wire:navigate class="hover:text-primary-700">Contacts</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $partner?->name ?? 'New contact' }}</span>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <livewire:views.form-view
                :model="\Modules\Contacts\Models\Partner::class"
                model-key="contacts.partner"
                :record-id="$partner?->id"
                title="{{ $partner ? 'Edit contact' : 'New contact' }}"
                redirect-to="{{ url('/app/contacts/partner') }}"
                :key="'partner-form-'.($partner?->id ?? 'new')" />
        </div>

        @if ($partner)
            <div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                    <h2 class="mb-3 text-sm font-semibold text-chrome-800">Chatter</h2>
                    <livewire:chatter :record="$partner" :key="'partner-chatter-'.$partner->id" />
                </div>
            </div>
        @endif
    </div>
</div>

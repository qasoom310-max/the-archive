{{--
    Settings → Online store. The address and header names stay English: they
    are copied verbatim into the shop website and must match byte for byte.
--}}
<div class="mx-auto max-w-3xl p-4 sm:p-6">
    @include('partials.settings-nav', ['active' => 'online_store'])

    <h1 class="mb-1 mt-4 text-lg font-bold text-chrome-900">{{ __('Online store') }}</h1>
    <p class="mb-4 text-sm text-chrome-500">{{ __('How the shop website reads your products and sends its orders into this database.') }}</p>

    @if ($saved)
        <p class="mb-3 rounded-xl bg-emerald-50 px-4 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ __('Saved.') }}</p>
    @endif

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <label class="flex items-start gap-3">
            <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-chrome-300 text-primary-500 focus:ring-primary-500">
            <span>
                <span class="text-sm font-medium text-chrome-800">{{ __('Connect the online store') }}</span>
                <span class="block text-xs text-chrome-500">{{ __('Off, the website can read nothing and send nothing. Every request is checked against the shared secret.') }}</span>
            </span>
        </label>
        @error('enabled') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

        <div class="mt-5">
            <span class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-chrome-500">{{ __('Shared secret') }}</span>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-chrome-600">{{ $hasSecret ? __('A secret is set.') : __('No secret yet.') }}</span>
                <button type="button" wire:click="generateSecret" wire:confirm="{{ $hasSecret ? __('Make a new secret? The website stops working until you give it the new one.') : __('Make a secret now?') }}"
                        class="rounded-lg border border-chrome-200 px-3 py-1.5 text-sm font-medium text-chrome-700 transition hover:bg-chrome-50">
                    {{ $hasSecret ? __('Make a new secret') : __('Generate') }}
                </button>
            </div>
            @if ($generated !== '')
                <div class="mt-2 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200">
                    <p class="text-xs text-amber-900">{{ __('Copy this into the shop website now. It is never shown again.') }}</p>
                    <p class="mt-1 select-all break-all font-mono text-xs text-chrome-800" dir="ltr">{{ $generated }}</p>
                </div>
            @endif
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-3">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-chrome-600">{{ __('WhatsApp number shown on the website') }}</span>
                <input type="text" wire:model="whatsappNumber" dir="ltr" class="o-input w-full text-sm" placeholder="+973 3300 0000">
                @error('whatsappNumber') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-chrome-600">{{ __('Delivery charge to the customer') }}</span>
                <input type="number" step="0.001" min="0" wire:model="deliveryCharge" class="o-input w-full text-sm">
                @error('deliveryCharge') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-chrome-600">{{ __('Free delivery from (leave blank for never)') }}</span>
                <input type="number" step="0.001" min="0" wire:model="freeDeliveryOver" class="o-input w-full text-sm">
                @error('freeDeliveryOver') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="mt-5 flex justify-end">
            <button type="button" wire:click="save" class="o-btn-primary text-sm">{{ __('Save') }}</button>
        </div>
    </div>

    <div class="mt-4 rounded-2xl bg-chrome-50 p-5 ring-1 ring-chrome-200">
        <h2 class="text-sm font-semibold text-chrome-800">{{ __('What to put in the shop website') }}</h2>
        <dl class="mt-3 space-y-3 text-sm">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Address') }}</dt>
                <dd class="select-all break-all font-mono text-xs text-chrome-700" dir="ltr">{{ $baseUrl }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Database') }}</dt>
                <dd class="font-mono text-xs text-chrome-700" dir="ltr">{{ $workspaceId }}</dd>
            </div>
        </dl>
        <p class="mt-3 text-xs text-chrome-500">{{ __('Website orders wait under POS → Remote sales → Website orders until someone accepts them.') }}</p>
    </div>
</div>

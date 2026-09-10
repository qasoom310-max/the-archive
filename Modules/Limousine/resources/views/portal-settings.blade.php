<div class="mx-auto max-w-2xl p-4 sm:p-6">
    <h1 class="mb-1 text-xl font-semibold text-chrome-900">{{ __('Service Portal') }}</h1>
    <p class="mb-5 text-sm text-chrome-500">{{ __('Connect the Wanaan website so a booking can be paid online.') }}</p>

    @include('partials.settings-nav', ['active' => 'limo_portal'])

    @if ($saved)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700 ring-1 ring-emerald-100">
            {{ __('Saved.') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <div>
            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Website address') }}</label>
            <input type="url" wire:model="portalUrl" placeholder="https://wanaan-bh.com" class="o-input w-full" dir="ltr">
            @error('portalUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Shared secret') }}</label>
            <input type="password" wire:model="sharedSecret" autocomplete="new-password"
                placeholder="{{ $hasSecret ? __('Set — leave blank to keep') : __('Enter the secret') }}" class="o-input w-full" dir="ltr">
            <p class="mt-1 text-xs text-chrome-500">{{ __('Must match the WANAAN_PORTAL_SECRET on the website. Leave blank to keep the current one.') }}</p>
            @error('sharedSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="rounded-lg bg-chrome-50 px-4 py-3">
            <label class="flex items-center gap-3">
                <input type="checkbox" wire:model="enabled" class="size-4 rounded border-chrome-300 text-primary-500">
                <span class="text-sm font-medium text-chrome-800">{{ __('Send new booking payment links to the website') }}</span>
            </label>
            <p class="mt-1 ms-7 text-xs text-chrome-500">{{ __('Turn this off to stop all online payment links immediately.') }}</p>
            @error('enabled') <p class="mt-1 ms-7 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Payment callback address') }}</label>
            <input type="text" value="{{ $callbackUrl }}" readonly onclick="this.select()" class="o-input w-full bg-chrome-50 text-chrome-500" dir="ltr">
            <p class="mt-1 text-xs text-chrome-500">{{ __('Give this to the website so it can confirm payments back to the ERP.') }}</p>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="o-btn-primary" wire:loading.attr="disabled">{{ __('Save') }}</button>
        </div>
    </form>
</div>

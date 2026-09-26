{{--
    Settings → Web Bookings. English by design in the parts that are an address
    or a header name: they are copied verbatim into WordPress, and translating
    them would be translating a thing that has to match byte for byte.
--}}
<div class="mx-auto max-w-3xl p-4 sm:p-6">
    @include('partials.settings-nav')

    <h1 class="mb-1 mt-4 text-lg font-bold text-chrome-900">{{ __('Web bookings') }}</h1>
    <p class="mb-4 text-sm text-chrome-500">
        {{ __('How the website sends car-rental bookings into this database.') }}
    </p>

    @if ($saved)
        <p class="mb-3 rounded-xl bg-emerald-50 px-4 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ __('Saved.') }}</p>
    @endif

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">

        <label class="flex items-start gap-3">
            <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-chrome-300 text-primary-500 focus:ring-primary-500">
            <span>
                <span class="text-sm font-medium text-chrome-800">{{ __('Accept bookings from the website') }}</span>
                <span class="block text-xs text-chrome-500">{{ __('Off, nothing is accepted at all. Requests are always checked against the shared secret below.') }}</span>
            </span>
        </label>
        @error('enabled') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

        <div class="mt-5">
            <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-chrome-500">{{ __('Shared secret') }}</label>
            <div class="flex flex-wrap gap-2">
                <input type="text" wire:model="sharedSecret" class="o-input flex-1 font-mono text-sm" dir="ltr"
                       placeholder="{{ $hasSecret ? __('Leave blank to keep the one you have') : __('Generate one, or paste your own') }}">
                <button type="button" wire:click="generateSecret"
                        class="rounded-lg border border-chrome-200 px-3 py-1.5 text-sm font-medium text-chrome-700 transition hover:bg-chrome-50">
                    {{ __('Generate') }}
                </button>
            </div>
            <p class="mt-1 text-xs text-chrome-500">
                {{ __('The same value goes into the WordPress plugin. It is stored encrypted and never shown again once saved.') }}
            </p>

            @if ($generated !== '')
                <p class="mt-2 rounded-xl bg-amber-50 px-4 py-2 text-xs text-amber-900 ring-1 ring-amber-200">
                    {{ __('Copy this into WordPress now — it is not shown again after you save.') }}
                </p>
            @endif
        </div>

        <div class="mt-5 flex justify-end">
            <button type="button" wire:click="save" class="o-btn-primary text-sm">{{ __('Save') }}</button>
        </div>
    </div>

    <div class="mt-4 rounded-2xl bg-chrome-50 p-5 ring-1 ring-chrome-200">
        <h2 class="text-sm font-semibold text-chrome-800">{{ __('What to put in WordPress') }}</h2>
        <dl class="mt-3 space-y-3 text-sm">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Address') }}</dt>
                <dd class="select-all break-all font-mono text-xs text-chrome-700" dir="ltr">{{ $endpoint }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Database') }}</dt>
                <dd class="font-mono text-xs text-chrome-700" dir="ltr">ws = {{ $workspaceId ?? 'main' }}</dd>
            </div>
        </dl>
        <p class="mt-3 text-xs text-chrome-500">
            {{ __('Every request is signed with the shared secret. Nothing secret is stored in the plugin file itself.') }}
        </p>
    </div>
</div>

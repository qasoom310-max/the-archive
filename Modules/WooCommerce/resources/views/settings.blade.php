<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-5 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">Settings</h1>
            <p class="text-sm text-chrome-500">WooCommerce store connection — push POS products to your website.</p>
        </div>
        <button wire:click="save" class="o-btn-primary">
            <span wire:loading.remove wire:target="save">Save</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>

    @include('partials.settings-nav', ['active' => 'woocommerce'])

    @if ($saved)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            WooCommerce settings saved.
        </div>
    @endif

    <div class="space-y-5 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
        <div class="grid items-start gap-2 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                Enabled
                <span class="mt-0.5 block text-xs font-normal text-chrome-400">Push products to the store when they are added or changed.</span>
            </label>
            <div class="sm:col-span-2">
                <button type="button" wire:click="$toggle('enabled')"
                    class="relative inline-flex h-6 w-11 items-center rounded-full transition
                        {{ $enabled ? 'bg-primary-500' : 'bg-chrome-300' }}">
                    <span class="inline-block size-4 transform rounded-full bg-white transition
                        {{ $enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                </button>
                @error('enabled') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        @php
            $fields = [
                ['storeUrl', 'Store URL', 'Your WordPress site, e.g. https://shop.example.com (HTTPS, WooCommerce installed).'],
                ['apiVersion', 'REST API version', 'Usually wc/v3.'],
            ];
        @endphp
        @foreach ($fields as [$model, $label, $hint])
            <div class="grid items-start gap-2 sm:grid-cols-3" wire:key="wc-{{ $model }}">
                <label class="pt-2 text-sm font-medium text-chrome-800">
                    {{ $label }}
                    <span class="mt-0.5 block text-xs font-normal text-chrome-400">{{ $hint }}</span>
                </label>
                <div class="sm:col-span-2">
                    <input type="text" wire:model="{{ $model }}" class="o-input">
                    @error($model) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        @endforeach

        @php
            $secrets = [
                ['consumerKey', 'Consumer key', $hasConsumerKey],
                ['consumerSecret', 'Consumer secret', $hasConsumerSecret],
            ];
        @endphp
        @foreach ($secrets as [$model, $label, $isSet])
            <div class="grid items-start gap-2 sm:grid-cols-3" wire:key="wc-{{ $model }}">
                <label class="pt-2 text-sm font-medium text-chrome-800">
                    {{ $label }}
                    <span class="mt-0.5 block text-xs font-normal {{ $isSet ? 'text-emerald-600' : 'text-chrome-400' }}">
                        {{ $isSet ? 'Configured — leave blank to keep current.' : 'Not set.' }}
                    </span>
                </label>
                <div class="sm:col-span-2">
                    <input type="password" autocomplete="new-password"
                        wire:model="{{ $model }}" class="o-input"
                        placeholder="{{ $isSet ? '•••••••• (unchanged)' : '' }}">
                </div>
            </div>
        @endforeach

        <div class="grid items-start gap-2 border-t border-chrome-100 pt-5 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                How to get the keys
                <span class="mt-0.5 block text-xs font-normal text-chrome-400">
                    In WordPress: WooCommerce → Settings → Advanced → REST API → Add key (Read/Write).
                </span>
            </label>
            <div class="sm:col-span-2 self-center">
                <button type="button" wire:click="syncAllNow"
                    class="rounded-md px-3 py-1.5 text-sm font-medium ring-1 transition
                        {{ $configured ? 'text-primary-700 ring-primary-300 hover:bg-primary-50' : 'cursor-not-allowed text-chrome-400 ring-chrome-200' }}"
                    @disabled(! $configured)>
                    Sync all active products now
                </button>
                @if (! $configured)
                    <p class="mt-2 text-xs text-chrome-400">Save your store settings (URL, keys, Enabled) first to turn this on.</p>
                @endif
                @if ($syncMessage !== '')
                    <p class="mt-2 text-xs font-medium text-emerald-600">{{ $syncMessage }}</p>
                @endif
            </div>
        </div>
    </div>
</div>

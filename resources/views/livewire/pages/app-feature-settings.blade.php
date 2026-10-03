<div class="mx-auto max-w-2xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/' . $module) }}" wire:navigate class="hover:text-primary-700">{{ $moduleLabel }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ __('Settings') }}</span>
    </div>

    <div class="mb-5">
        <h1 class="text-xl font-bold text-chrome-900">{{ $moduleLabel }} — {{ __('Features') }}</h1>
        <p class="text-sm text-chrome-500">
            {{ __('Turn parts of this app on or off for this database. Changes apply immediately.') }}
        </p>
    </div>

    @if ($saved)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            {{ __('Saved.') }}
        </div>
    @endif

    <form wire:submit="save" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <div class="divide-y divide-chrome-100">
            @forelse ($features as $feature)
                <label wire:key="feat-{{ $feature->value }}"
                    class="flex cursor-pointer items-center justify-between gap-4 py-3">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-chrome-800">{{ __($feature->label()) }}</span>
                        @if ($feature->description() !== '')
                            <span class="mt-0.5 block text-xs text-chrome-500">{{ __($feature->description()) }}</span>
                        @endif
                    </span>
                    {{-- iOS-style switch --}}
                    <span class="relative inline-flex shrink-0">
                        <input type="checkbox" wire:model="toggles.{{ $feature->value }}" class="peer sr-only">
                        <span class="h-6 w-11 rounded-full bg-chrome-300 transition-colors peer-checked:bg-primary-500"></span>
                        <span class="absolute start-0.5 top-0.5 size-5 rounded-full bg-white transition-transform peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5"></span>
                    </span>
                </label>
            @empty
                <p class="py-6 text-center text-sm text-chrome-400">{{ __('No configurable features for this app.') }}</p>
            @endforelse
        </div>

        @if ($hasPrinter)
            {{-- The network receipt printer: the till sends each receipt
                 straight to this address (Epson ePOS-Print). --}}
            <div class="mt-5 border-t border-chrome-100 pt-5">
                <h2 class="text-sm font-semibold text-chrome-900">{{ __('Receipt printer') }}</h2>
                <p class="mt-0.5 text-xs text-chrome-500">
                    {{ __('An Epson receipt printer on the shop network (e.g. TM-T20III). Receipts print straight to it, with no print window. Leave the address empty to print through the browser instead.') }}
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="printer-ip" class="mb-1 block text-xs font-medium text-chrome-600">{{ __('Printer IP address') }}</label>
                        <input id="printer-ip" type="text" wire:model="printerIp" dir="ltr" inputmode="decimal"
                            placeholder="192.168.1.50" class="o-input w-full">
                        @error('printerIp')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="printer-width" class="mb-1 block text-xs font-medium text-chrome-600">{{ __('Paper width') }}</label>
                        <select id="printer-width" wire:model="printerWidth" class="o-input w-full">
                            @foreach ($paperWidths as $dots => $label)
                                <option value="{{ $dots }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm text-chrome-700">
                    <input type="checkbox" wire:model="printerHttps" class="rounded border-chrome-300">
                    {{ __('Secure connection (https) — needed when the ERP is opened over https') }}
                </label>

                @if ($printerConfig !== null)
                    <div class="mt-4 flex flex-wrap items-center gap-3"
                        x-data="{ busy: false, message: '', ok: false }">
                        <button type="button" class="o-btn-ghost text-sm" x-bind:disabled="busy"
                            x-on:click="busy = true; message = ''; window.printReceiptTest(@js($printerConfig))
                                .then(() => { ok = true; message = @js(__('Test page sent to the printer.')); })
                                .catch((e) => { ok = false; message = e.message; })
                                .finally(() => { busy = false; })">
                            {{ __('Print a test page') }}
                        </button>
                        <a href="{{ ($printerHttps ? 'https://' : 'http://') . $printerIp }}" target="_blank" rel="noopener"
                            class="text-xs text-primary-700 hover:underline">{{ __('Open the printer page') }}</a>
                        <p x-show="message !== ''" x-cloak x-text="message"
                            x-bind:class="ok ? 'text-emerald-600' : 'text-red-600'" class="w-full text-xs font-medium"></p>
                    </div>
                    <p class="mt-2 text-xs text-chrome-500">
                        {{ __('First time on each device: open the printer page once and accept its security warning, and allow the browser to reach devices on the local network when it asks.') }}
                    </p>
                @endif
            </div>
        @endif

        <div class="mt-5 flex justify-end border-t border-chrome-100 pt-4">
            <button type="submit" class="o-btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    </form>
</div>

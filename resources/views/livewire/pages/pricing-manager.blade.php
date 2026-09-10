<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Fares')" :subtitle="__('The prices the website publishes. Edited here, nowhere else.')" icon="wallet" accent="emerald">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-chrome-100 px-3 py-1.5 text-xs font-medium text-chrome-600">
                {{ __('Version') }} <span class="font-bold text-chrome-800">{{ $version }}</span>
                @if ($updatedAt) · {{ $updatedAt }} @endif
            </span>
            <button type="button" wire:click="sendToWebsite" wire:loading.attr="disabled" wire:target="sendToWebsite" class="o-btn-ghost">
                <span wire:loading.remove wire:target="sendToWebsite">{{ __('Send update to website') }}</span>
                <span wire:loading wire:target="sendToWebsite">{{ __('Sending…') }}</span>
            </button>
        </x-slot:actions>
    </x-page-header>

    @if (session('pricing_toast'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">{{ session('pricing_toast') }}</div>
    @endif

    @if ($pingResult)
        <div class="mb-4 rounded-lg px-4 py-2.5 text-sm font-medium {{ $pingOk ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100' : 'bg-amber-50 text-amber-800 ring-1 ring-amber-100' }}">
            {{ $pingResult }}
            @unless ($pingOk)
                <span class="block text-xs font-normal">{{ __('The fares are saved. The website refreshes on its own timer anyway.') }}</span>
            @endunless
        </div>
    @endif

    @if (! $ready)
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-100">
            {{ __('Pricing is not set up on this database yet.') }}
        </div>
    @elseif ($services->isEmpty())
        <div class="rounded-xl bg-chrome-50 px-4 py-3 text-sm text-chrome-600 ring-1 ring-chrome-200">
            {{ __('No services yet. Run the pricing seed for this database to load the published fares.') }}
        </div>
    @else

    {{-- One tab per service --}}
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($services as $item)
            <button type="button" wire:click="$set('service', '{{ $item->id }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $service === $item->id ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $item->name_en }}
                @unless ($item->active)
                    <span class="rounded bg-chrome-200 px-1.5 text-[10px] font-semibold uppercase text-chrome-600">{{ __('Hidden') }}</span>
                @endunless
                @if ($item->estimated)
                    <span class="rounded bg-amber-100 px-1.5 text-[10px] font-semibold uppercase text-amber-800">{{ __('Estimated') }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($current)
        <form wire:submit="save" class="space-y-6">
            @if ($current->estimated)
                {{-- These fares were derived, not given. The flag clears itself
                     the first time a human saves this grid. --}}
                <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                    <span class="font-semibold">{{ __('These fares are estimates, not your prices.') }}</span>
                    <span class="block text-xs">{{ __('They were worked out from your other rates so the service is complete. Check every cell and save — the warning clears once you do.') }}</span>
                </div>
            @endif

            {{-- The grid: options down, cars across --}}
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-5">
                <div class="mb-3">
                    <h2 class="text-sm font-semibold text-chrome-800">{{ $current->name_en }} — {{ __('fares per car') }}</h2>
                    <p class="text-xs text-chrome-400">{{ __('All amounts in BHD, per car, driver included. Leave a cell empty only if the option is switched off.') }}</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-chrome-500">
                                <th class="py-2 text-start">{{ __('Option') }}</th>
                                @foreach ($cars as $car)
                                    <th class="w-28 px-2 py-2 text-center">
                                        <div class="font-semibold text-chrome-700">{{ $car->name_en }}</div>
                                        <div class="text-[10px] font-normal normal-case text-chrome-400">{{ $car->model }}</div>
                                    </th>
                                @endforeach
                                <th class="w-20 py-2 text-center">{{ __('Shown') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-chrome-100">
                            @foreach ($current->options as $option)
                                <tr wire:key="opt-{{ $option->id }}">
                                    <td class="py-2 pe-3 align-top">
                                        <div class="font-medium text-chrome-800">{{ $option->short_en ?: $option->label_en }}</div>
                                        <div class="text-[11px] text-chrome-400">
                                            {{ $option->code }}@if ($option->hours) · {{ trans_choice(':count hour|:count hours', $option->hours, ['count' => $option->hours]) }}@endif
                                        </div>
                                    </td>
                                    @foreach ($cars as $car)
                                        <td class="px-1 py-2 align-top">
                                            <input type="number" step="0.001" min="0" inputmode="decimal"
                                                   wire:model="rates.{{ $option->id }}.{{ $car->id }}"
                                                   class="o-input w-full text-center text-sm @error('rates.'.$option->id.'.'.$car->id) border-red-400 @enderror"
                                                   aria-label="{{ $option->label_en }} — {{ $car->name_en }}">
                                            @error('rates.'.$option->id.'.'.$car->id)
                                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                                            @enderror
                                        </td>
                                    @endforeach
                                    <td class="px-1 py-2 text-center align-top">
                                        <input type="checkbox" wire:model="optionActive.{{ $option->id }}"
                                               class="mt-2 size-4 rounded border-chrome-300 text-primary-600 focus:ring-primary-500"
                                               aria-label="{{ __('Show :option on the website', ['option' => $option->label_en]) }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                {{-- Extra hours + return factor --}}
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-5">
                    <h2 class="text-sm font-semibold text-chrome-800">{{ __('Beyond the booked trip') }}</h2>
                    <p class="mb-3 text-xs text-chrome-400">{{ __('Leave blank where it does not apply to this service.') }}</p>

                    <div class="mb-4">
                        <div class="mb-1 text-xs font-medium text-chrome-500">{{ __('Charge per extra hour') }}</div>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            @foreach ($cars as $car)
                                <label class="block">
                                    <span class="text-[11px] text-chrome-600">{{ $car->name_en }}</span>
                                    <input type="number" step="0.001" min="0" inputmode="decimal"
                                           wire:model="extraHours.{{ $car->id }}" class="o-input mt-1 w-full text-sm">
                                </label>
                            @endforeach
                        </div>
                        @error('extraHours.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <label class="block max-w-xs">
                        <span class="text-xs font-medium text-chrome-500">{{ __('Return trip costs the one-way ×') }}</span>
                        <input type="number" step="0.01" min="1" max="9.99" inputmode="decimal"
                               wire:model="returnFactor" class="o-input mt-1 w-full text-sm" placeholder="{{ __('blank = no return offered') }}">
                        <span class="mt-1 block text-[11px] text-chrome-400">{{ __('2.00 = two full one-ways · 1.80 = a 10% discount on the way back') }}</span>
                        @error('returnFactor') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </label>
                </div>

                {{-- Offer --}}
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-5">
                    <h2 class="text-sm font-semibold text-chrome-800">{{ __('Offer on this service') }}</h2>
                    <p class="mb-3 text-xs text-chrome-400">{{ __('Starts/Ends are the travel dates this discount applies to — turning the offer on makes it bookable right away, even for trips before Starts arrives. Turning it off (or Ends passing) is what takes it off the website.') }}</p>

                    <label class="mb-3 flex items-center gap-2 text-sm text-chrome-700">
                        <input type="checkbox" wire:model="offerActive" class="size-4 rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                        {{ __('Offer is on') }}
                    </label>

                    <div class="mb-3">
                        <div class="mb-1 text-xs font-medium text-chrome-500">{{ __('Applies to') }}</div>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            @foreach ($cars as $car)
                                <label class="flex items-center gap-1.5 text-xs text-chrome-700">
                                    <input type="checkbox" value="{{ $car->id }}" wire:model="offerCarIds"
                                           class="size-3.5 rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                                    {{ $car->name_en }}
                                </label>
                            @endforeach
                        </div>
                        @error('offerCarIds') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-medium text-chrome-500">{{ __('Percent off') }}</span>
                            <input type="number" step="0.01" min="0" max="100" inputmode="decimal"
                                   wire:model="offerPercent" class="o-input mt-1 w-full text-sm">
                            @error('offerPercent') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </label>
                        <div></div>
                        <label class="block">
                            <span class="text-xs font-medium text-chrome-500">{{ __('Label (English)') }}</span>
                            <input type="text" wire:model="offerLabelEn" class="o-input mt-1 w-full text-sm" placeholder="{{ __('National Day 25%') }}">
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-chrome-500">{{ __('Label (Arabic)') }}</span>
                            <input type="text" wire:model="offerLabelAr" dir="rtl" class="o-input mt-1 w-full text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-chrome-500">{{ __('Starts') }}</span>
                            <x-date-field wire:model="offerStarts" class="o-input mt-1 w-full text-sm" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-chrome-500">{{ __('Ends') }}</span>
                            <x-date-field wire:model="offerEnds" class="o-input mt-1 w-full text-sm" />
                            @error('offerEnds') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </label>
                    </div>
                </div>
            </div>

            {{-- Everything the widget shows that isn't a fare. Shared by every
                 service, edited here so it is never hard-coded on the site. --}}
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-5">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Website settings') }}</h2>
                <p class="mb-3 text-xs text-chrome-400">{{ __('Used across every service on the website.') }}</p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-medium text-chrome-500">{{ __('WhatsApp number') }}</span>
                        <input type="text" inputmode="numeric" dir="ltr" wire:model="whatsapp" class="o-input mt-1 w-full text-sm" placeholder="97317474949">
                        <span class="mt-1 block text-[11px] text-chrome-400">{{ __('Digits only, including the country code.') }}</span>
                        @error('whatsapp') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-chrome-500">{{ __('Least notice before a trip (hours)') }}</span>
                        <input type="number" min="0" max="168" inputmode="numeric" wire:model="leadHours" class="o-input mt-1 w-full text-sm">
                        <span class="mt-1 block text-[11px] text-chrome-400">{{ __('How far ahead a customer must book online.') }}</span>
                        @error('leadHours') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </label>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <span class="text-xs text-chrome-400">{{ __('Saving publishes a new version and tells the website.') }}</span>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="o-btn-primary">
                    <span wire:loading.remove wire:target="save">{{ __('Save fares') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </div>
        </form>
    @endif

    @endif
</div>

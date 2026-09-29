<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Corporate rates')" :subtitle="__('Prices agreed with companies that have a deal with us. Not shown on the website.')" icon="wallet" accent="indigo" />

    @if (session('corporate_toast'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">{{ session('corporate_toast') }}</div>
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

    {{-- Whose rates: the standard corporate rate, or one company's own deal. --}}
    <div class="mb-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-chrome-700">{{ __('Rates for') }}</label>
                <x-searchable-select wire:model.live="company" :options="$companyOptions"
                    :placeholder="__('All companies — standard corporate rate')"
                    :search-placeholder="__('Search a company…')" />
            </div>
            <div class="text-xs text-chrome-500 sm:pt-6">
                @if ($companyName)
                    {{ __('Only :company pays these. A blank cell uses the standard corporate rate, then the website fare.', ['company' => $companyName]) }}
                @else
                    {{ __('Every company customer pays these unless it has a deal of its own. A blank cell uses the website fare.') }}
                @endif
            </div>
        </div>

        @if ($companiesWithDeals !== [])
            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                <span class="text-xs text-chrome-500">{{ __('Companies with their own deal:') }}</span>
                <button type="button" wire:click="$set('company', '')"
                        class="rounded-full px-2.5 py-1 text-xs font-medium {{ $company === '' ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-700 hover:bg-chrome-200' }}">
                    {{ __('Standard') }}
                </button>
                @foreach ($companiesWithDeals as $deal)
                    <button type="button" wire:click="$set('company', '{{ $deal['id'] }}')"
                            class="rounded-full px-2.5 py-1 text-xs font-medium {{ $company === (string) $deal['id'] ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-700 hover:bg-chrome-200' }}">
                        {{ $deal['name'] }} <span class="opacity-60">· {{ $deal['count'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- One tab per service --}}
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($services as $item)
            <button type="button" wire:click="$set('service', '{{ $item->id }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $service === $item->id ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $item->name_en }}
            </button>
        @endforeach
    </div>

    @if ($current)
        <form wire:submit="save" class="space-y-4">
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-5">
                <div class="mb-3">
                    <h2 class="text-sm font-semibold text-chrome-800">{{ $current->name_en }} — {{ $companyName ?? __('Standard corporate rate') }}</h2>
                    <p class="text-xs text-chrome-400">{{ __('All amounts in BHD, per car, driver included. The grey figure in an empty cell is what applies instead.') }}</p>
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
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-chrome-100">
                            @foreach ($current->options as $option)
                                <tr wire:key="corp-{{ $company }}-{{ $option->id }}">
                                    <td class="py-2 pe-3 align-top">
                                        <div class="font-medium text-chrome-800">{{ $option->short_en ?: $option->label_en }}</div>
                                        <div class="text-[11px] text-chrome-400">
                                            {{ $option->code }}@if ($option->hours) · {{ trans_choice(':count hour|:count hours', $option->hours, ['count' => $option->hours]) }}@endif
                                            @unless ($option->active) · {{ __('not on the website') }}@endunless
                                        </div>
                                    </td>
                                    @foreach ($cars as $car)
                                        <td class="px-1 py-2 align-top">
                                            <input type="number" step="0.001" min="0" inputmode="decimal"
                                                   wire:model="rates.{{ $option->id }}.{{ $car->id }}"
                                                   placeholder="{{ $fallbacks[$option->id][$car->id] ?? '' }}"
                                                   class="o-input w-full text-center text-sm placeholder:text-chrome-300 @error('rates.'.$option->id.'.'.$car->id) border-red-400 @enderror"
                                                   aria-label="{{ $option->label_en }} — {{ $car->name_en }}">
                                            @error('rates.'.$option->id.'.'.$car->id)
                                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                                            @enderror
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <span class="text-xs text-chrome-400">{{ __('Round trips and extra hours follow the website settings for the service.') }}</span>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="o-btn-primary">
                    <span wire:loading.remove wire:target="save">{{ __('Save corporate rates') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </div>
        </form>
    @endif

    @endif
</div>

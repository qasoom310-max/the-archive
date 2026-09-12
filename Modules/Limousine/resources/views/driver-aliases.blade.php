@php
    $money = fn (float $v): string => number_format($v, 2) . ' ' . __('BD');
    $year = function (string $at): string {
        return $at === '' ? '' : substr($at, 0, 4);
    };
@endphp

<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header
        :title="__('Old driver names')"
        :subtitle="__('Trips brought over from the old system name a login, not a driver. Say who each one was.')"
        icon="user" accent="indigo">
        <x-slot:actions>
            <a href="{{ url('/app/limousine/driver') }}" wire:navigate class="o-btn-ghost">{{ __('Drivers') }}</a>
            @if ($canSave)
                <button type="button" wire:click="save" class="o-btn-primary">
                    <span wire:loading.remove wire:target="save">{{ __('Save matches') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- What this screen is for, in the owner's words rather than the system's.
         Anyone opening it cold needs to know that nothing is being rewritten. --}}
    <div class="mb-4 rounded-2xl border border-chrome-200 bg-white p-4 text-sm leading-relaxed text-chrome-600">
        <p>
            {{ __('The old system recorded the driver as the account that was logged in, so a trip says "kown" or "smakhlooq" instead of a name. Match each one to a driver in the register, or mark it as the office when nobody drove it.') }}
        </p>
        <p class="mt-2 text-chrome-500">
            {{ __('The trips themselves are never changed. Earnings, a driver\'s job history and petty cash all read them through these matches, so a name matched wrongly is put right by changing it here.') }}
        </p>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-xl bg-chrome-100 p-1">
            <button type="button" wire:click="setFilter('todo')"
                class="o-btn {{ $filter === 'todo' ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600' }}">
                {{ __('Still to decide') }} ({{ number_format($todoCount) }})
            </button>
            <button type="button" wire:click="setFilter('all')"
                class="o-btn {{ $filter === 'all' ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-600' }}">
                {{ __('All names') }} ({{ number_format($totalCount) }})
            </button>
        </div>

        <input type="search" wire:model.live.debounce.300ms="search" class="o-input ms-auto w-full sm:w-64"
            placeholder="{{ __('Search a name…') }}">
    </div>

    <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-5">
        @if ($rows === [])
            <p class="py-8 text-center text-sm text-chrome-500">
                @if ($totalCount === 0)
                    {{ __('No trip names a driver in text, so there is nothing to match.') }}
                @else
                    {{ __('Nothing left to decide here.') }}
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead>
                        <tr class="border-b border-chrome-200 text-[11px] font-bold uppercase tracking-wider text-chrome-400">
                            <th class="py-2 text-start">{{ __('Name on the trip') }}</th>
                            <th class="py-2 text-end">{{ __('Trips') }}</th>
                            <th class="py-2 text-end">{{ __('Collected') }}</th>
                            <th class="py-2 text-center">{{ __('Active') }}</th>
                            <th class="py-2 text-start ps-4 w-[22rem]">{{ __('This is') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-100">
                        @foreach ($rows as $row)
                            <tr wire:key="alias-{{ $row['slug'] }}">
                                <td class="py-3 pe-3">
                                    <span class="font-semibold text-chrome-900">{{ $row['display'] }}</span>
                                    @if ($row['linked'] > 0 && $row['linked'] < $row['trips'])
                                        <span class="ms-2 text-[11px] text-chrome-400">
                                            {{ __(':count of these already point at a driver.', ['count' => number_format($row['linked'])]) }}
                                        </span>
                                    @elseif ($row['linked'] >= $row['trips'])
                                        <span class="ms-2 text-[11px] text-emerald-600">{{ __('Already linked') }}</span>
                                    @endif
                                </td>
                                <td class="py-3 text-end text-chrome-600">{{ number_format($row['trips']) }}</td>
                                <td class="py-3 text-end font-medium text-chrome-800">{{ $money($row['collected']) }}</td>
                                <td class="py-3 text-center text-[11px] text-chrome-500">
                                    @php $from = $year($row['from']); $to = $year($row['to']); @endphp
                                    {{ $from === '' ? '—' : ($from === $to ? $from : $from . '–' . $to) }}
                                </td>
                                <td class="py-3 ps-4">
                                    <x-searchable-select
                                        wire:model="choices.{{ $row['slug'] }}"
                                        :options="$options"
                                        :placeholder="__('— Not decided —')"
                                        :search-placeholder="__('Type a driver\'s name…')" />
                                    @if (! $row['decided'] && $row['suggestion'] !== null)
                                        <p class="mt-1 text-[11px] text-amber-600">
                                            {{ __('Guessed from the spelling — check it before saving.') }}
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($canSave && $rows !== [])
        <div class="mt-4 flex justify-end">
            <button type="button" wire:click="save" class="o-btn-primary">
                <span wire:loading.remove wire:target="save">{{ __('Save matches') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    @endif
</div>

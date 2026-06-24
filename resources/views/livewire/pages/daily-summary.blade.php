@php
    use App\Erp\Money\Currencies;
    $money = fn ($v) => Currencies::format((float) $v);
@endphp

<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Daily Summary') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Money in (sales) vs money out (purchases) for the day.') }}</p>
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Date') }}</label>
            <input type="date" wire:model.live="date" class="o-input">
        </div>
    </div>

    <p class="mb-3 text-sm font-medium text-chrome-600">{{ $dayLabel }}</p>

    {{-- Headline cards: Sales · Purchases · Net --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Daily Sales') }}</p>
            <p class="mt-1 text-2xl font-bold text-emerald-600">{{ $money($today['sales']) }}</p>
            <p class="mt-1 text-xs text-chrome-400">{{ __('Money in') }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Daily Purchases') }}</p>
            <p class="mt-1 text-2xl font-bold text-chrome-700">{{ $money($today['purchases']) }}</p>
            <p class="mt-1 text-xs text-chrome-400">{{ __('Money out') }}</p>
        </div>
        @php $neg = $today['net'] < 0; @endphp
        <div class="rounded-xl p-5 shadow-sm ring-1 {{ $neg ? 'bg-red-50 ring-red-200' : 'bg-emerald-50 ring-emerald-200' }}">
            <p class="text-xs font-semibold uppercase tracking-wide {{ $neg ? 'text-red-600' : 'text-emerald-700' }}">{{ __('Net Profit') }}</p>
            <p class="mt-1 text-2xl font-bold {{ $neg ? 'text-red-600' : 'text-emerald-700' }}">{{ $money($today['net']) }}</p>
            <p class="mt-1 text-xs {{ $neg ? 'text-red-500' : 'text-emerald-600' }}">
                {{ $neg ? __('In the red — spent more than earned') : __('In profit') }}
            </p>
        </div>
    </div>

    {{-- 7-day trend --}}
    <div class="mt-6 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <table class="min-w-full divide-y divide-chrome-200 text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Day') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Daily Sales') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Daily Purchases') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Net Profit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @foreach ($history as $row)
                    @php $rowNeg = $row['totals']['net'] < 0; @endphp
                    <tr class="hover:bg-chrome-50">
                        <td class="px-4 py-2.5 text-chrome-700">{{ $row['label'] }}</td>
                        <td class="px-4 py-2.5 text-end tabular-nums text-chrome-700">{{ $money($row['totals']['sales']) }}</td>
                        <td class="px-4 py-2.5 text-end tabular-nums text-chrome-500">{{ $money($row['totals']['purchases']) }}</td>
                        <td class="px-4 py-2.5 text-end font-semibold tabular-nums {{ $rowNeg ? 'text-red-600' : 'text-emerald-700' }}">{{ $money($row['totals']['net']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{--
    Quarterly VAT report on limousine expenses. Everything in BHD. Bahrain VAT
    (the claim) and foreign VAT (a cost) are separate tables with separate
    totals, so one can never be added into the other by reading a column.
--}}
@php
    $money = static fn (float $v): string => \App\Erp\Money\Currencies::format($v, 'BHD');
    $th = 'px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide text-chrome-500';
    $thNum = 'px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-chrome-500';
    $td = 'px-3 py-2 text-sm text-chrome-700';
    $tdNum = 'px-3 py-2 text-end text-sm tabular-nums text-chrome-800';
    $t = $report['totals'];
@endphp

<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <x-page-header :title="__('VAT report')" :subtitle="__('VAT paid on expenses, for the quarterly VAT return. All figures in BHD.')" icon="wallet" accent="indigo">
        <x-slot:actions>
            <a href="{{ url('/app/limousine/expense') }}" wire:navigate class="o-btn-ghost print:hidden">{{ __('Expenses') }}</a>
            <a href="{{ url('/app/limousine/expense/vat-report/export') . '?year=' . $shownYear . '&quarter=' . $shownQuarter }}" class="o-btn-ghost print:hidden" data-vat-export>{{ __('Excel (CSV)') }}</a>
            <button type="button" onclick="window.print()" class="o-btn-primary print:hidden">{{ __('Print') }}</button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden" data-vat-quarters>
        <select class="o-input w-auto" wire:change="setQuarter(parseInt($event.target.value), {{ $shownQuarter }})">
            @foreach ($years as $y)<option value="{{ $y }}" @selected($y === $shownYear)>{{ $y }}</option>@endforeach
        </select>
        @foreach ([1 => __('Jan – Mar'), 2 => __('Apr – Jun'), 3 => __('Jul – Sep'), 4 => __('Oct – Dec')] as $q => $label)
            <button type="button" wire:click="setQuarter({{ $shownYear }}, {{ $q }})"
                    class="rounded-full px-3 py-1 text-sm font-medium transition-colors {{ $q === $shownQuarter ? 'bg-primary-400 text-chrome-900' : 'bg-white text-chrome-700 ring-1 ring-chrome-200 hover:bg-chrome-50' }}">{{ $label }}</button>
        @endforeach
    </div>

    <p class="mb-4 text-sm text-chrome-600" data-vat-period>
        {{ __('Period') }}: <span class="font-semibold text-chrome-900" dir="ltr">{{ $from->format('d/m/Y') }} – {{ $to->format('d/m/Y') }}</span>
    </p>

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-2 ring-emerald-200" data-vat-claim>
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ __('Bahrain VAT to claim') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-chrome-900" dir="ltr">{{ $money($t['bahrain_vat']) }}</p>
            <p class="mt-1 text-xs text-chrome-500">{{ __('On purchases of :amount before VAT', ['amount' => $money($t['bahrain_net'])]) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5" data-vat-foreign>
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ __('Foreign VAT (not claimed)') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-chrome-900" dir="ltr">{{ $money($t['foreign_vat']) }}</p>
            <p class="mt-1 text-xs text-chrome-500">{{ __('Paid in another country. A cost, not part of the Bahrain return.') }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Bills with no VAT') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-chrome-900" dir="ltr">{{ $money($t['none_total']) }}</p>
            <p class="mt-1 text-xs text-chrome-500">{{ __('Bills: :count', ['count' => $t['none_count']]) }} · {{ __('All expenses: :amount', ['amount' => $money($t['all_total'])]) }}</p>
        </div>
    </div>

    @if ($report['missing'] > 0)
        <p class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 print:hidden" data-vat-missing>
            {{ __('Bahrain VAT bills with no supplier name or no photo of the bill: :count. A claim needs the tax invoice from the supplier.', ['count' => $report['missing']]) }}
        </p>
    @endif

    @foreach ([
        ['key' => 'bahrain', 'title' => __('Bahrain VAT bills (10%)'), 'vat' => __('VAT'), 'empty' => __('No bills with Bahrain VAT in this quarter.')],
        ['key' => 'foreign', 'title' => __('Foreign VAT bills (not claimed)'), 'vat' => __('Foreign VAT'), 'empty' => __('No bills with foreign VAT in this quarter.')],
    ] as $section)
        <section class="mb-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/5" data-vat-section="{{ $section['key'] }}">
            <h2 class="border-b border-chrome-100 px-4 py-3 text-sm font-bold text-chrome-900">{{ $section['title'] }}</h2>
            @if ($report[$section['key']] === [])
                <p class="px-4 py-6 text-center text-sm text-chrome-400">{{ $section['empty'] }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-chrome-100">
                        <thead class="bg-chrome-50">
                            <tr>
                                <th class="{{ $th }}">{{ __('Date') }}</th>
                                <th class="{{ $th }}">{{ __('Reference') }}</th>
                                <th class="{{ $th }}">{{ __('Paid to') }}</th>
                                <th class="{{ $th }}">{{ __('Category') }}</th>
                                <th class="{{ $thNum }}">{{ __('Before VAT') }}</th>
                                <th class="{{ $thNum }}">{{ $section['vat'] }}</th>
                                <th class="{{ $thNum }}">{{ __('Total') }}</th>
                                <th class="{{ $th }} print:hidden">{{ __('Bill') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-chrome-100">
                            @foreach ($report[$section['key']] as $expense)
                                <tr wire:key="vat-{{ $expense->id }}">
                                    <td class="{{ $td }} whitespace-nowrap" dir="ltr">{{ $expense->date->format('d/m/Y') }}</td>
                                    <td class="{{ $td }} whitespace-nowrap"><a href="{{ url('/app/limousine/expense/' . $expense->id) }}" wire:navigate class="font-medium text-primary-800 hover:underline">{{ $expense->reference }}</a></td>
                                    <td class="{{ $td }}">
                                        {{ $expense->payee ?: '—' }}
                                        @if ($expense->bill_currency !== 'BHD' && $expense->bill_amount !== null)
                                            <span class="block text-xs text-chrome-400" dir="ltr">{{ \App\Erp\Money\Currencies::format($expense->bill_amount, $expense->bill_currency) }} × {{ rtrim(rtrim(number_format((float) $expense->bill_rate, 6, '.', ''), '0'), '.') }}</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }}">{{ __($categories[$expense->category] ?? $expense->category) }}</td>
                                    <td class="{{ $tdNum }}" dir="ltr">{{ $money($expense->net_amount) }}</td>
                                    <td class="{{ $tdNum }} font-semibold" dir="ltr">{{ $money($expense->vat_amount) }}</td>
                                    <td class="{{ $tdNum }}" dir="ltr">{{ $money($expense->amount) }}</td>
                                    <td class="{{ $td }} print:hidden">
                                        @if ($expense->bill_path)
                                            <a href="{{ url('/app/limousine/expense/' . $expense->id . '/bill') }}" target="_blank" rel="noopener" class="text-primary-800 hover:underline">{{ __('View') }}</a>
                                        @else
                                            <span class="text-chrome-300">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-chrome-50">
                            <tr>
                                <td colspan="4" class="px-3 py-2 text-sm font-bold text-chrome-900">{{ __('Total') }}</td>
                                <td class="{{ $tdNum }} font-bold" dir="ltr">{{ $money($t[$section['key'] . '_net']) }}</td>
                                <td class="{{ $tdNum }} font-bold" dir="ltr">{{ $money($t[$section['key'] . '_vat']) }}</td>
                                <td class="{{ $tdNum }} font-bold" dir="ltr">{{ $money($t[$section['key'] . '_total']) }}</td>
                                <td class="print:hidden"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>
    @endforeach

    <p class="text-xs text-chrome-400">{{ __('This report covers Limousine expenses only. It does not include VAT on sales, or purchases recorded in other apps.') }}</p>
</div>

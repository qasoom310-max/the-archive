<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Petty Cash')" :subtitle="__('The float, and what is out with the drivers.')" icon="wallet" accent="emerald">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" onclick="document.getElementById('import-petty-cash').classList.toggle('hidden')" class="o-btn-ghost">{{ __('Import') }}</button>
                <button type="button" wire:click="openTopUp" class="o-btn-ghost">{{ __('Top up float') }}</button>
                <button type="button" wire:click="openIssue" class="o-btn-primary">{{ __('Send to driver') }}</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Import petty-cash advances from a CSV (managers). Direct POST —
         Hostinger-safe. The expected columns are the same shape this
         screen's own export prints. A row lands directly at its recorded
         status/totals, never through settle() — no per-receipt lines and no
         expense-ledger rows are created, since the CSV has no line detail. --}}
    @if ($canManage)
        <div id="import-petty-cash" class="mb-4 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import petty cash (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Columns: Reference, Driver, Date, Given, Receipts, Status. Other columns are ignored. The same driver, date and amount seen before is skipped.') }}</p>
            <form method="POST" action="{{ url('/app/limousine/petty_cash/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="file" name="file" accept=".csv,text/csv,text/plain" required class="text-sm">
                <button type="submit" class="o-btn-primary text-sm">{{ __('Import') }}</button>
            </form>
            @error('file')<p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
            @if (session('toast'))
                <p class="mt-2 text-xs font-medium text-emerald-600">{{ session('toast') }}</p>
            @endif
        </div>
    @endif

    {{-- The float in three figures: what is in the drawer, what is out on the
         road, and what still needs the accountant's signature. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <button type="button" wire:click="$set('tab', 'float')" data-float-card
                class="rounded-xl bg-white p-5 text-start shadow-sm ring-1 ring-chrome-900/5 transition hover:ring-primary-400 {{ $tab === 'float' ? 'ring-2 ring-primary-400' : '' }}">
            <div class="flex items-center justify-between text-sm font-medium text-chrome-600">
                {{ __('Float balance') }}
                <span class="text-xs font-medium text-primary-700">{{ __('Full details') }} <span class="rtl:hidden">→</span><span class="hidden rtl:inline">←</span></span>
            </div>
            <div class="mt-2 text-2xl font-bold {{ $balance < 0 ? 'text-red-600' : 'text-chrome-800' }}">{{ \App\Erp\Views\ValueFormat::money($balance) }}</div>
        </button>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-sm font-medium text-chrome-600">{{ __('Out with drivers') }}</div>
            <div class="mt-2 text-2xl font-bold text-amber-700">{{ \App\Erp\Views\ValueFormat::money($outstanding) }}</div>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-sm font-medium text-chrome-600">{{ __('Waiting for confirmation') }}</div>
            <div class="mt-2 text-2xl font-bold text-chrome-800">{{ $toConfirmCount }}</div>
        </div>
    </div>

    @php $tabs = ['open' => __('Open'), 'cleared' => __('Cleared'), 'float' => __('Float history'), 'report' => __('Report')]; @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
                @if ($key === 'open' && $openCount > 0)
                    <span class="rounded-full bg-chrome-100 px-1.5 text-[11px] text-chrome-500">{{ $openCount }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($tab === 'float')
        @php
            $m = fn ($v) => \App\Erp\Views\ValueFormat::money($v);
            $t = $statement['totals'];
            $kinds = [
                'topup' => [__('Top-up'), 'bg-emerald-100 text-emerald-700'],
                'advance' => [__('Given to driver'), 'bg-amber-100 text-amber-700'],
                'paid_back' => [__('Paid back (overspent)'), 'bg-sky-100 text-sky-700'],
            ];
        @endphp

        {{-- How the balance adds up, all time: the same three figures
             floatBalance() sums, so this always lands on the card above. --}}
        <div class="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]" data-float-summary>
            <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('How the float balance adds up') }}</h2>
            <ul class="divide-y divide-chrome-100 text-sm">
                <li class="flex items-center justify-between gap-3 py-2">
                    <span class="text-chrome-700">{{ __('Topped up') }} <span class="text-xs text-chrome-400">({{ trans_choice(':count top-up|:count top-ups', $t['topup_count'], ['count' => $t['topup_count']]) }})</span></span>
                    <span class="font-semibold text-emerald-700">+ {{ $m($t['topups']) }}</span>
                </li>
                <li class="flex items-center justify-between gap-3 py-2">
                    <span class="text-chrome-700">{{ __('Given to drivers') }} <span class="text-xs text-chrome-400">({{ trans_choice(':count advance|:count advances', $t['given_count'], ['count' => $t['given_count']]) }})</span></span>
                    <span class="font-semibold text-chrome-800">− {{ $m($t['given']) }}</span>
                </li>
                <li class="flex items-center justify-between gap-3 py-2">
                    <span class="text-chrome-700">{{ __('Paid back to drivers who spent more') }} <span class="text-xs text-chrome-400">({{ trans_choice(':count advance|:count advances', $t['paid_back_count'], ['count' => $t['paid_back_count']]) }})</span></span>
                    <span class="font-semibold text-chrome-800">− {{ $m($t['paid_back']) }}</span>
                </li>
                <li class="flex items-center justify-between gap-3 py-2 text-base font-bold text-chrome-900">
                    <span>{{ __('Float balance') }}</span>
                    <span class="{{ $balance < 0 ? 'text-red-600' : '' }}">{{ $m($balance) }}</span>
                </li>
            </ul>
            <div class="mt-3 grid gap-2 text-xs text-chrome-500 sm:grid-cols-2">
                <p>{{ __('Of the money given, still out with drivers (not settled yet)') }}: <span class="font-semibold text-amber-700">{{ $m($outstanding) }}</span></p>
                <p>{{ __('Short on settled advances') }}: <span class="font-semibold text-chrome-700">{{ $m($t['short']) }}</span> ({{ trans_choice(':count advance|:count advances', $t['short_count'], ['count' => $t['short_count']]) }}). {{ __('Recorded only: it does not come back into the float.') }}</p>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Month') }}</label>
                <div class="flex items-center gap-1">
                    <button type="button" wire:click="shiftFloatMonth(-1)" class="rounded-lg border border-chrome-200 px-2 py-1.5 text-sm text-chrome-600 hover:bg-chrome-50" aria-label="{{ __('Previous month') }}"><span class="rtl:hidden">‹</span><span class="hidden rtl:inline">›</span></button>
                    <input type="month" wire:model.live="floatMonth" class="o-input text-sm">
                    <button type="button" wire:click="shiftFloatMonth(1)" class="rounded-lg border border-chrome-200 px-2 py-1.5 text-sm text-chrome-600 hover:bg-chrome-50" aria-label="{{ __('Next month') }}"><span class="rtl:hidden">›</span><span class="hidden rtl:inline">‹</span></button>
                </div>
            </div>
            <button type="button" wire:click="$set('floatMonth', 'all')"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium {{ $statement['allTime'] ? 'border-primary-400 bg-primary-400 text-chrome-900' : 'border-chrome-200 text-chrome-600 hover:bg-chrome-50' }}">{{ __('All time') }}</button>
            <span class="pb-2 text-sm font-semibold text-chrome-800">{{ $statement['label'] }}</span>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <div class="text-xs font-medium text-chrome-500">{{ __('Opening balance') }}</div>
                <div class="mt-1 text-lg font-bold text-chrome-800">{{ $m($statement['opening']) }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <div class="text-xs font-medium text-chrome-500">{{ __('Money in') }}</div>
                <div class="mt-1 text-lg font-bold text-emerald-700">+ {{ $m($statement['in']) }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <div class="text-xs font-medium text-chrome-500">{{ __('Money out') }}</div>
                <div class="mt-1 text-lg font-bold text-chrome-800">− {{ $m($statement['out']) }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <div class="text-xs font-medium text-chrome-500">{{ __('Closing balance') }}</div>
                <div class="mt-1 text-lg font-bold {{ $statement['closing'] < 0 ? 'text-red-600' : 'text-chrome-800' }}">{{ $m($statement['closing']) }}</div>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap items-center gap-2" x-data="listExportCopy">
            <button type="button" x-on:click="copyTable('limo-float-table')"
                    class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
                <span x-show="! copied">{{ __('Copy') }}</span>
                <span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span>
            </button>
        </div>

        <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <table class="w-full min-w-[860px] divide-y divide-chrome-100 text-sm" id="limo-float-table">
                <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Movement') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Driver') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('By') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Note') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('In') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Out') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Balance') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-50">
                    @unless ($statement['allTime'])
                        <tr class="bg-chrome-50/60">
                            <td class="px-4 py-2 text-chrome-500" colspan="8">{{ __('Opening balance') }}</td>
                            <td class="px-4 py-2 text-end font-semibold text-chrome-800">{{ $m($statement['opening']) }}</td>
                        </tr>
                    @endunless
                    @forelse ($statement['rows'] as $row)
                        @php [$kindLabel, $kindClass] = $kinds[$row['kind']]; @endphp
                        <tr data-float-row="{{ $row['kind'] }}">
                            <td class="whitespace-nowrap px-4 py-2 text-chrome-600">{{ \Illuminate\Support\Carbon::parse($row['date'])->isoFormat('DD-MMM-YYYY') }}</td>
                            <td class="px-4 py-2"><span class="whitespace-nowrap rounded px-2 py-0.5 text-[11px] font-semibold {{ $kindClass }}">{{ $kindLabel }}</span></td>
                            <td class="px-4 py-2">
                                @if ($row['advance_id'] !== null)
                                    <a href="{{ url('/app/limousine/petty_cash/' . $row['advance_id']) }}" wire:navigate class="font-medium text-primary-700 hover:underline">{{ $row['reference'] }}</a>
                                @else
                                    <span class="text-chrome-300">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-chrome-700">{{ $row['driver'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-500">{{ $row['by'] ?: '—' }}</td>
                            <td class="max-w-[16rem] truncate px-4 py-2 text-chrome-500" title="{{ $row['notes'] }}">{{ $row['notes'] ?: '' }}</td>
                            <td class="px-4 py-2 text-end font-medium text-emerald-700">{{ $row['in'] > 0 ? $m($row['in']) : '' }}</td>
                            <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ $row['out'] > 0 ? $m($row['out']) : '' }}</td>
                            <td class="px-4 py-2 text-end font-semibold {{ $row['balance'] < 0 ? 'text-red-600' : 'text-chrome-900' }}">{{ $m($row['balance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No money moved in or out of the float in this period.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'report')
        @php $catTotal = array_sum($report['byCategory']); @endphp
        <div class="mb-4 flex items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Month') }}</label>
                <input type="month" wire:model.live="month" class="o-input text-sm">
            </div>
            <span class="pb-2 text-sm text-chrome-500">{{ __('Spent') }}: <span class="font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($report['spent']) }}</span></span>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('By category') }}</h2>
                @if ($report['byCategory'] === [])
                    <p class="text-sm text-chrome-400">{{ __('Nothing settled this month.') }}</p>
                @else
                    <ul class="divide-y divide-chrome-100 text-sm">
                        @foreach ($report['byCategory'] as $cat => $sum)
                            <li class="flex items-center justify-between py-2">
                                <span class="text-chrome-700">{{ $cat }}</span>
                                <span class="font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($sum) }}</span>
                            </li>
                        @endforeach
                        <li class="flex items-center justify-between py-2 font-bold text-chrome-900">
                            <span>{{ __('Total') }}</span>
                            <span>{{ \App\Erp\Views\ValueFormat::money($catTotal) }}</span>
                        </li>
                    </ul>
                @endif
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('By driver') }}</h2>
                @if ($report['byDriver']->isEmpty())
                    <p class="text-sm text-chrome-400">{{ __('Nothing settled this month.') }}</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="text-xs font-semibold uppercase tracking-wide text-chrome-500">
                            <tr>
                                <th class="py-1 text-start">{{ __('Driver') }}</th>
                                <th class="py-1 text-end">{{ __('Given') }}</th>
                                <th class="py-1 text-end">{{ __('Receipts') }}</th>
                                <th class="py-1 text-end">{{ __('Short') }}</th>
                                <th class="py-1 text-end">{{ __('Paid back') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-chrome-50">
                            @foreach ($report['byDriver'] as $row)
                                <tr>
                                    <td class="py-1.5 text-chrome-700">{{ $row['name'] }}</td>
                                    <td class="py-1.5 text-end text-chrome-700">{{ \App\Erp\Views\ValueFormat::money($row['issued']) }}</td>
                                    <td class="py-1.5 text-end text-chrome-700">{{ \App\Erp\Views\ValueFormat::money($row['receipts']) }}</td>
                                    <td class="py-1.5 text-end {{ $row['shortfall'] > 0 ? 'font-semibold text-red-600' : 'text-chrome-400' }}">{{ \App\Erp\Views\ValueFormat::money($row['shortfall']) }}</td>
                                    <td class="py-1.5 text-end {{ $row['excess'] > 0 ? 'font-semibold text-emerald-700' : 'text-chrome-400' }}">{{ \App\Erp\Views\ValueFormat::money($row['excess']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- No "salary deductions" list: a driver's petty cash difference is
             never taken from his pay (the owner's decision, 2026-10-27). The
             Short column above only records it. --}}
    @else
    @php
        $exportQuery = http_build_query([
            'tab' => $tab,
            'title' => __('Petty Cash'),
        ]);
    @endphp
    <div class="mb-3 flex flex-wrap items-center gap-2" x-data="listExportCopy">
        <button type="button" x-on:click="copyTable('limo-petty-cash-table')"
                class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">
            <span x-show="! copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span>
        </button>
        <a href="{{ url('/app/limousine/petty_cash/export/csv') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('CSV') }}</a>
        <a href="{{ url('/app/limousine/petty_cash/export/excel') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Excel') }}</a>
        <a href="{{ url('/app/limousine/petty_cash/export/pdf') }}?{{ $exportQuery }}"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('PDF') }}</a>
        <a href="{{ url('/app/limousine/petty_cash/export/print') }}?{{ $exportQuery }}" target="_blank" rel="noopener"
           class="rounded-lg border border-chrome-200 px-3 py-1.5 text-xs font-medium text-chrome-600 transition hover:bg-chrome-50">{{ __('Print') }}</a>
    </div>

        <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
            <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm" id="limo-petty-cash-table">
                <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Driver') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Given') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Receipts') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                    </tr>
                </thead>
                @if ($groups !== null)
                    {{-- Open: one row per driver; tap it for each reference,
                         date and amount behind the totals. --}}
                    @forelse ($groups as $group)
                        <tbody x-data="{ open: false }" wire:key="pgrp-{{ $group['key'] }}" class="border-b border-chrome-100">
                            <tr class="cursor-pointer bg-white hover:bg-chrome-50" x-on:click="open = ! open">
                                <td class="px-4 py-2.5 font-semibold text-chrome-800" colspan="2">
                                    <span class="inline-flex items-center gap-2">
                                        <svg class="size-3.5 shrink-0 text-chrome-400 transition rtl:-scale-x-100" :class="open && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.2 14.8a.75.75 0 0 1 0-1.06L10.94 10 7.2 6.26a.75.75 0 1 1 1.06-1.06l4.27 4.27a.75.75 0 0 1 0 1.06l-4.27 4.27a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/></svg>
                                        {{ $group['name'] }}
                                        <span class="rounded-full bg-chrome-100 px-2 py-0.5 text-[11px] font-medium text-chrome-600">{{ trans_choice(':count advance|:count advances', $group['advances']->count(), ['count' => $group['advances']->count()]) }}</span>
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-chrome-600">{{ $group['advances']->first()?->date?->isoFormat('DD-MMM-YYYY') }}</td>
                                <td class="px-4 py-2.5 text-end font-semibold text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($group['given']) }}</td>
                                <td class="px-4 py-2.5 text-end text-chrome-700">{{ \App\Erp\Views\ValueFormat::money($group['receipts']) }}</td>
                                <td class="px-4 py-2.5 text-xs font-medium text-chrome-600">{{ __('Still out') }}: <span class="font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money(max(0, $group['given'] - $group['receipts'])) }}</span></td>
                            </tr>
                            @foreach ($group['advances'] as $advance)
                                @php
                                    $sb = ['issued' => 'bg-amber-100 text-amber-700', 'confirmed' => 'bg-sky-100 text-sky-700', 'cleared' => 'bg-emerald-100 text-emerald-700'][$advance->status] ?? 'bg-chrome-200 text-chrome-700';
                                    $receipts = $advance->isCleared() ? $advance->receipts_total : (float) ($advance->lines_sum_amount ?? 0);
                                @endphp
                                <tr x-show="open" x-cloak wire:key="padv-{{ $advance->id }}" class="cursor-pointer bg-chrome-50/60 hover:bg-chrome-100"
                                    onclick="window.location='{{ url('/app/limousine/petty_cash/' . $advance->id) }}'">
                                    <td class="py-2 pe-4 ps-10 font-medium text-chrome-800">{{ $advance->reference }}</td>
                                    <td class="px-4 py-2 text-chrome-400">{{ $advance->driver?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-chrome-600">{{ $advance->date?->isoFormat('DD-MMM-YYYY') }}</td>
                                    <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($advance->amount) }}</td>
                                    <td class="px-4 py-2 text-end text-chrome-700">{{ \App\Erp\Views\ValueFormat::money($receipts) }}</td>
                                    <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($advance->status)) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    @empty
                        <tbody><tr><td colspan="6" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No petty cash yet. Top up the float, then send to a driver.') }}</td></tr></tbody>
                    @endforelse
                @else
                <tbody class="divide-y divide-chrome-50">
                    @forelse ($advances as $advance)
                        @php
                            $sb = ['issued' => 'bg-amber-100 text-amber-700', 'confirmed' => 'bg-sky-100 text-sky-700', 'cleared' => 'bg-emerald-100 text-emerald-700'][$advance->status] ?? 'bg-chrome-200 text-chrome-700';
                            $receipts = $advance->isCleared() ? $advance->receipts_total : (float) ($advance->lines_sum_amount ?? 0);
                        @endphp
                        <tr wire:key="padv-{{ $advance->id }}" class="cursor-pointer hover:bg-chrome-50"
                            onclick="window.location='{{ url('/app/limousine/petty_cash/' . $advance->id) }}'">
                            <td class="px-4 py-2 font-medium text-chrome-800">{{ $advance->reference }}</td>
                            <td class="px-4 py-2 text-chrome-700">{{ $advance->driver?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-chrome-600">{{ $advance->date?->isoFormat('DD-MMM-YYYY') }}</td>
                            <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($advance->amount) }}</td>
                            <td class="px-4 py-2 text-end text-chrome-700">{{ \App\Erp\Views\ValueFormat::money($receipts) }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($advance->status)) }}</span>
                                @if ($advance->isCleared() && $advance->shortfall > 0)
                                    <span class="ms-1 text-[11px] font-semibold text-red-600">− {{ \App\Erp\Views\ValueFormat::money($advance->shortfall) }} {{ __('short') }}</span>
                                @elseif ($advance->isCleared() && $advance->excess > 0)
                                    <span class="ms-1 text-[11px] font-semibold text-emerald-700">+ {{ \App\Erp\Views\ValueFormat::money($advance->excess) }} {{ __('paid back') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No petty cash yet. Top up the float, then send to a driver.') }}</td></tr>
                    @endforelse
                </tbody>
                @endif
            </table>
        </div>

        @if ($advances !== null)
            <div class="mt-4">{{ $advances->links('vendor.pagination.compact') }}</div>
        @endif
    @endif

    {{-- Top up --}}
    @if ($toppingUp)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4" x-on:keydown.escape.window="$wire.closeTopUp()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeTopUp()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Top up float') }}</h2>
                <div class="mt-4 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Amount') }}</label>
                            <input type="number" step="0.001" min="0" wire:model="topAmount" class="o-input w-full">
                            @error('topAmount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Date') }}</label>
                            <x-date-field wire:model="topDate" class="o-input w-full" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Note') }}</label>
                        <input type="text" wire:model="topNotes" class="o-input w-full">
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeTopUp" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveTopUp" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Top up float') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Send to driver --}}
    @if ($issuing)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4" x-on:keydown.escape.window="$wire.closeIssue()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeIssue()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Send to driver') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">{{ __('Float balance') }}: <span class="font-semibold">{{ \App\Erp\Views\ValueFormat::money($balance) }}</span></p>
                <div class="mt-4 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Driver') }}</label>
                        <x-searchable-select wire:model="issueDriverId" class="o-input w-full"
                            :options="collect($drivers)->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all()" />
                        @error('issueDriverId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Amount') }}</label>
                            <input type="number" step="0.001" min="0" wire:model="issueAmount" class="o-input w-full">
                            @error('issueAmount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Date') }}</label>
                            <x-date-field wire:model="issueDate" class="o-input w-full" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Note') }}</label>
                        <input type="text" wire:model="issueNotes" class="o-input w-full">
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeIssue" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveIssue" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Send to driver') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>

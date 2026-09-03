<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Petty Cash')" :subtitle="__('The float, and what is out with the drivers.')" icon="wallet" accent="emerald">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" wire:click="openTopUp" class="o-btn-ghost">{{ __('Top up float') }}</button>
                <button type="button" wire:click="openIssue" class="o-btn-primary">{{ __('Send to driver') }}</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- The float in three figures: what is in the drawer, what is out on the
         road, and what still needs the accountant's signature. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-sm font-medium text-chrome-600">{{ __('Float balance') }}</div>
            <div class="mt-2 text-2xl font-bold {{ $balance < 0 ? 'text-red-600' : 'text-chrome-800' }}">{{ \App\Erp\Views\ValueFormat::money($balance) }}</div>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-sm font-medium text-chrome-600">{{ __('Out with drivers') }}</div>
            <div class="mt-2 text-2xl font-bold text-amber-700">{{ \App\Erp\Views\ValueFormat::money($outstanding) }}</div>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-sm font-medium text-chrome-600">{{ __('Waiting for confirmation') }}</div>
            <div class="mt-2 text-2xl font-bold text-chrome-800">{{ $toConfirmCount }}</div>
        </div>
    </div>

    @php $tabs = ['open' => __('Open'), 'cleared' => __('Cleared'), 'report' => __('Report')]; @endphp
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

    @if ($tab === 'report')
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
                                <th class="py-1 text-end">{{ __('From salary') }}</th>
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

        {{-- The list that goes to payroll: each settlement's decision, with the
             advance it came from so it can always be argued from the paper. --}}
        <div class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Salary deductions this month') }}</h2>
            @if ($report['deductions']->isEmpty())
                <p class="text-sm text-chrome-400">{{ __('No deductions — every driver accounted for his money.') }}</p>
            @else
                <ul class="divide-y divide-chrome-100 text-sm">
                    @foreach ($report['deductions'] as $a)
                        <li wire:key="ded-{{ $a->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span class="font-medium text-chrome-800">{{ $a->driver?->name ?? '—' }}</span>
                            <span class="text-chrome-500">{{ $a->reference }} · {{ $a->settled_at?->isoFormat('DD-MMM-YYYY') }}</span>
                            <span class="text-chrome-500">{{ __('Given :given, receipts :receipts', ['given' => \App\Erp\Views\ValueFormat::money($a->amount), 'receipts' => \App\Erp\Views\ValueFormat::money($a->receipts_total)]) }}</span>
                            <span class="font-bold text-red-600">− {{ \App\Erp\Views\ValueFormat::money($a->shortfall) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
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
                                    <span class="ms-1 text-[11px] font-semibold text-red-600">− {{ \App\Erp\Views\ValueFormat::money($advance->shortfall) }} {{ __('salary') }}</span>
                                @elseif ($advance->isCleared() && $advance->excess > 0)
                                    <span class="ms-1 text-[11px] font-semibold text-emerald-700">+ {{ \App\Erp\Views\ValueFormat::money($advance->excess) }} {{ __('paid back') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No petty cash yet. Top up the float, then send to a driver.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($advances !== null)
            <div class="mt-4">{{ $advances->links() }}</div>
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

@php use App\Erp\Views\ValueFormat; @endphp
@php
    // Compact number for the dense matrix cells (no currency symbol — the column
    // headers carry it — but at the configured currency's precision).
    $fmt = static function (float $v): string {
        if ($v <= 0) {
            return '0';
        }
        return rtrim(rtrim(number_format($v, \App\Erp\Money\Currencies::active()->decimals, '.', ''), '0'), '.');
    };
@endphp
<div class="mx-auto max-w-screen-2xl p-4 sm:p-6">
    <x-page-header :title="__('Sales')" :subtitle="__('Booking revenue by car and month, with seasonal insight.')" icon="chart" accent="primary" />

    {{-- Peak-season heads-up. --}}
    @if ($seasonBanner)
        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 1l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.8 4.8 17.2l1-5.8L1.5 7.2l5.9-.9L10 1z"/></svg>
            </span>
            <div>
                <p class="text-sm font-semibold text-emerald-900">{{ __('Peak season ahead: :month', ['month' => __(\Modules\Rental\Livewire\Sales::MONTHS[$seasonBanner['month']])]) }}</p>
                <p class="text-sm text-emerald-800">{{ __(':month is historically one of your strongest months (avg :amount). Make sure the fleet is ready and papers are renewed.', ['month' => __(\Modules\Rental\Livewire\Sales::MONTHS[$seasonBanner['month']]), 'amount' => ValueFormat::money($seasonBanner['avg'])]) }}</p>
            </div>
        </div>
    @endif

    {{-- Year picker + tools. --}}
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Year') }}</label>
                <select wire:model.live="year" class="o-input text-sm">
                    @foreach ($availableYears as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <a href="{{ url('/app/rental/sales/export?year=' . $year) }}" class="o-btn-ghost text-sm">{{ __('Export CSV') }}</a>
            <button type="button" onclick="window.print()" class="o-btn-ghost text-sm">{{ __('Print') }}</button>
            @if ($canManage)
                <button type="button" onclick="document.getElementById('import-history').classList.toggle('hidden')" class="o-btn-ghost text-sm">{{ __('Import history') }}</button>
            @endif
        </div>
        <div class="text-end">
            <p class="text-xs font-medium uppercase tracking-wide text-chrome-400">{{ __('Total revenue :year', ['year' => $year]) }}</p>
            <p class="text-2xl font-bold text-chrome-900">{{ ValueFormat::money($fleetTotal) }}</p>
        </div>
    </div>

    {{-- Import old monthly revenue (managers). Direct POST — Hostinger-safe. --}}
    @if ($canManage)
        <div id="import-history" class="mb-5 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import monthly revenue (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('A CSV with a Reg#/Plate column and month columns (Jan…Dec). Other columns are ignored. Re-importing a year replaces it.') }}</p>
            <form method="POST" action="{{ url('/app/rental/sales/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Year') }}</label>
                    <input type="number" name="year" min="2000" max="2100" value="{{ $year }}" required class="o-input w-28 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('CSV file') }}</label>
                    <input type="file" name="file" accept=".csv,text/csv,text/plain" required class="text-sm">
                </div>
                <button type="submit" class="o-btn-primary text-sm">{{ __('Import') }}</button>
            </form>
            @error('file')<p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
            @if (session('toast'))
                <p class="mt-2 text-xs font-medium text-emerald-600">{{ session('toast') }}</p>
            @endif
        </div>
    @endif

    {{-- Seasonality panel. --}}
    <div class="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Seasonality') }}</h2>
            @if ($hasHistory)
                <div class="flex items-center gap-3 text-xs">
                    <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded-sm bg-emerald-500"></span>{{ __('Peak month') }}</span>
                    <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded-sm bg-sky-400"></span>{{ __('Normal') }}</span>
                </div>
            @endif
        </div>

        @if (! $hasHistory)
            <p class="text-sm text-chrome-400">{{ __('Not enough history yet to spot seasons — keep recording bookings and the peak/dead months will appear here automatically.') }}</p>
        @else
            {{-- Average revenue per calendar month across all years. --}}
            <div class="flex items-end gap-2 overflow-x-auto pb-1" style="height: 140px;">
                @foreach ($months as $m => $label)
                    @php
                        $val = $seasonalAvg[$m] ?? 0;
                        $h = $seasonalMax > 0 ? max(4, (int) round($val / $seasonalMax * 110)) : 4;
                        $isPeak = in_array($m, $peakMonths, true);
                    @endphp
                    <div class="flex flex-1 flex-col items-center justify-end gap-1" style="min-width: 44px;">
                        <span class="text-[10px] font-medium text-chrome-500">{{ $val > 0 ? \App\Erp\Views\ValueFormat::money($val) : '' }}</span>
                        <div class="w-full rounded-t {{ $isPeak ? 'bg-emerald-500' : 'bg-sky-400' }}" style="height: {{ $h }}px;" title="{{ __($label) }}"></div>
                        <span class="text-[11px] {{ $isPeak ? 'font-bold text-emerald-700' : 'text-chrome-500' }}">{{ __($label) }}</span>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-chrome-400">{{ __('Average booking revenue per month across all recorded years. Taller green bars are your strongest seasons.') }}</p>
        @endif

        {{-- Best / slowest month this year. --}}
        @if ($bestMonth || $deadMonth)
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                @if ($bestMonth)
                    <div class="rounded-xl bg-emerald-50 px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-emerald-700">{{ __('Best month (:year)', ['year' => $year]) }}</p>
                        <p class="text-sm font-semibold text-emerald-900">{{ __($months[$bestMonth]) }} · {{ ValueFormat::money($fleetMonthly[$bestMonth]) }}</p>
                    </div>
                @endif
                @if ($deadMonth)
                    <div class="rounded-xl bg-amber-50 px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-amber-700">{{ __('Slowest month (:year)', ['year' => $year]) }}</p>
                        <p class="text-sm font-semibold text-amber-900">{{ __($months[$deadMonth]) }} · {{ ValueFormat::money($fleetMonthly[$deadMonth]) }}</p>
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- Booking-revenue matrix. --}}
    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="min-w-full divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-3 py-2 text-start">{{ __('Car') }}</th>
                    <th class="px-3 py-2 text-end">{{ __('Expected') }}</th>
                    @foreach ($months as $m => $label)
                        <th class="px-3 py-2 text-end {{ $bestMonth === $m ? 'text-emerald-600' : '' }}">{{ __($label) }}</th>
                    @endforeach
                    <th class="px-3 py-2 text-end">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($cars as $car)
                    <tr class="hover:bg-chrome-50">
                        <td class="whitespace-nowrap px-3 py-2">
                            <div class="font-medium text-chrome-800">{{ $car['name'] }}</div>
                            @if ($car['plate'])<div class="text-xs text-chrome-400">{{ $car['plate'] }}</div>@endif
                        </td>
                        <td class="px-3 py-2 text-end font-medium text-chrome-500">{{ $car['expected'] > 0 ? $fmt($car['expected']) : '—' }}</td>
                        @foreach ($months as $m => $label)
                            <td class="px-3 py-2 text-end {{ $car['months'][$m] > 0 ? 'text-emerald-700' : 'text-chrome-300' }}">{{ $fmt($car['months'][$m]) }}</td>
                        @endforeach
                        <td class="px-3 py-2 text-end font-bold text-chrome-900">{{ $fmt($car['total']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="15" class="px-3 py-10 text-center text-chrome-400">{{ __('No cars yet.') }}</td></tr>
                @endforelse
            </tbody>
            @if ($cars !== [])
                <tfoot class="border-t-2 border-chrome-200 bg-chrome-50 font-semibold text-chrome-800">
                    <tr>
                        <td class="px-3 py-2">{{ __('Fleet total') }}</td>
                        <td class="px-3 py-2"></td>
                        @foreach ($months as $m => $label)
                            <td class="px-3 py-2 text-end {{ $bestMonth === $m ? 'text-emerald-700' : '' }}">{{ $fmt($fleetMonthly[$m]) }}</td>
                        @endforeach
                        <td class="px-3 py-2 text-end text-primary-700">{{ $fmt($fleetTotal) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

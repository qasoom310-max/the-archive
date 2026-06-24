@php use App\Erp\Money\Currencies; $money = fn ($v) => Currencies::format((float) $v); @endphp

<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Payroll') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Each employee\'s salary for the month. Paid totals feed the monthly profit.') }}</p>
        </div>
        <div class="flex items-end gap-3">
            <a href="{{ url('/hr/employees') }}" wire:navigate class="mb-1 text-xs font-medium text-primary-700 hover:underline">{{ __('Employees') }} →</a>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Month') }}</label>
                <input type="month" wire:model.live="month" class="o-input">
            </div>
        </div>
    </div>

    <p class="mb-3 text-sm font-medium text-chrome-600">{{ $monthLabel }}</p>

    <div class="mb-4 flex flex-wrap gap-2">
        <span class="o-chip bg-chrome-100 text-chrome-600">{{ __('Projected total') }}: <span class="font-semibold">{{ $money($total) }}</span></span>
        <span class="o-chip bg-emerald-50 text-emerald-700">{{ __('Paid so far') }}: <span class="font-semibold">{{ $money($paidTotal) }}</span></span>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <table class="min-w-full divide-y divide-chrome-200 text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Employee') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Net salary') }}</th>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Status') }}</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($rows as $row)
                    <tr wire:key="pay-{{ $row['employee']->id }}" class="hover:bg-chrome-50">
                        <td class="px-4 py-2.5 font-medium text-chrome-800">{{ $row['employee']->name }}</td>
                        <td class="px-4 py-2.5 text-end tabular-nums {{ $row['net'] < 0 ? 'text-red-600' : 'text-chrome-900' }}">{{ $money($row['net']) }}</td>
                        <td class="px-4 py-2.5">
                            @if ($row['paid'])
                                <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Paid') }}</span>
                            @else
                                <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Pending') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-end">
                            <a href="{{ url('/hr/employee/' . $row['employee']->id . '/payroll?month=' . $month) }}" wire:navigate class="text-xs font-medium text-primary-700 hover:underline">{{ __('Open') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No active employees.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

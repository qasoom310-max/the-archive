@php
    use App\Erp\Money\Currencies;
    $money = fn ($v) => Currencies::format((float) $v);
    $hrs = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
@endphp

<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div class="text-sm text-chrome-500">
            <div class="flex items-center gap-2">
                <a href="{{ url('/hr/employees') }}" wire:navigate class="hover:text-primary-700">{{ __('Employees') }}</a>
                <span>/</span>
                <a href="{{ url('/hr/employee/' . $employee->id) }}" wire:navigate class="hover:text-primary-700">{{ $employee->name }}</a>
                <span>/</span>
                <span class="font-medium text-chrome-700">{{ __('Payroll') }}</span>
            </div>
            <p class="mt-1 text-lg font-bold text-chrome-900">{{ $employee->name }} <span class="text-sm font-normal text-chrome-400">· {{ $monthLabel }}</span></p>
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Month') }}</label>
            <input type="month" wire:model.live="month" class="o-input">
        </div>
    </div>

    {{-- Payslip summary --}}
    @php $net = $calc['net']; $neg = $net < 0; @endphp
    <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Basic salary') }}</p>
                <p class="mt-1 text-lg font-bold text-chrome-900">{{ $money($calc['basic']) }}</p>
            </div>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Overtime') }}</p>
                <p class="mt-1 text-lg font-bold text-emerald-600">+{{ $money($calc['overtime_pay']) }}</p>
                <p class="text-[11px] text-chrome-400">{{ $hrs($calc['ot_day_hours']) }}h ×1.2 · {{ $hrs($calc['ot_night_hours']) }}h ×1.5</p>
            </div>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Absence deduction') }}</p>
                <p class="mt-1 text-lg font-bold text-red-600">−{{ $money($calc['absence_deduction']) }}</p>
                <p class="text-[11px] text-chrome-400">{{ $calc['absence_days'] }} {{ __('unpaid day(s)') }}</p>
            </div>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Net salary') }}</p>
                <p class="mt-1 text-lg font-bold {{ $neg ? 'text-red-600' : 'text-emerald-700' }}">{{ $money($net) }}</p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-chrome-100 pt-4">
            @if ($payslip)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                    {{ __('Paid') }} {{ $money($payslip->net) }} · {{ $payslip->paid_on?->isoFormat('MMM D') }}
                </span>
                <a href="{{ url('/hr/employee/' . $employee->id . '/payslip?month=' . $month) }}" target="_blank" class="o-btn-primary">{{ __('Payslip PDF') }}</a>
                <button wire:click="markPaid" class="text-sm font-medium text-chrome-600 hover:underline">{{ __('Re-finalise') }}</button>
                <button wire:click="unmarkPaid" class="text-sm text-red-500 hover:underline">{{ __('Unmark paid') }}</button>
            @else
                <button wire:click="markPaid" class="o-btn-primary">{{ __('Mark paid (finalise payslip)') }}</button>
                <span class="text-xs text-chrome-400">{{ __('Finalising snapshots these figures into a payslip and adds it to the monthly profit.') }}</span>
            @endif
        </div>
    </div>

    {{-- Overtime --}}
    <div class="mt-6 rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <div class="border-b border-chrome-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Overtime sessions') }}</h2>
            <p class="text-xs text-chrome-400">{{ __('Day 7 AM–7 PM = ×1.2, night 7 PM–7 AM = ×1.5. End before start = crossed midnight.') }}</p>
        </div>
        <div class="flex flex-wrap items-end gap-2 border-b border-chrome-100 px-4 py-3">
            <div><label class="mb-1 block text-[11px] font-semibold uppercase text-chrome-500">{{ __('Date') }}</label><input type="date" wire:model="otDate" class="o-input"></div>
            <div><label class="mb-1 block text-[11px] font-semibold uppercase text-chrome-500">{{ __('Start') }}</label><input type="time" wire:model="otStart" class="o-input"></div>
            <div><label class="mb-1 block text-[11px] font-semibold uppercase text-chrome-500">{{ __('End') }}</label><input type="time" wire:model="otEnd" class="o-input"></div>
            <button wire:click="addOvertime" class="o-btn-primary">{{ __('Add') }}</button>
            @error('otStart') <p class="w-full text-xs text-red-600">{{ $message }}</p> @enderror
            @error('otEnd') <p class="w-full text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <table class="min-w-full divide-y divide-chrome-100 text-sm">
            <tbody class="divide-y divide-chrome-100">
                @forelse ($overtimes as $o)
                    <tr wire:key="ot-{{ $o['row']->id }}">
                        <td class="px-4 py-2 text-chrome-700">{{ $o['row']->work_date->isoFormat('ddd, MMM D') }}</td>
                        <td class="px-4 py-2 text-chrome-500">{{ $o['row']->start_time }} – {{ $o['row']->end_time }}</td>
                        <td class="px-4 py-2 text-end text-chrome-600">{{ $hrs($o['day']) }}h <span class="text-chrome-400">×1.2</span></td>
                        <td class="px-4 py-2 text-end text-chrome-600">{{ $hrs($o['night']) }}h <span class="text-chrome-400">×1.5</span></td>
                        <td class="px-4 py-2 text-end"><button wire:click="removeOvertime({{ $o['row']->id }})" class="text-xs text-red-500 hover:underline">{{ __('remove') }}</button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-xs text-chrome-400">{{ __('No overtime this month.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Absences --}}
    <div class="mt-6 rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <div class="border-b border-chrome-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Absences & sick leave') }}</h2>
            <p class="text-xs text-chrome-400">{{ __('Absent = unpaid (deducts a day). Sick = paid (no deduction).') }}</p>
        </div>
        <div class="flex flex-wrap items-end gap-2 border-b border-chrome-100 px-4 py-3">
            <div><label class="mb-1 block text-[11px] font-semibold uppercase text-chrome-500">{{ __('Date') }}</label><input type="date" wire:model="absDate" class="o-input"></div>
            <div><label class="mb-1 block text-[11px] font-semibold uppercase text-chrome-500">{{ __('Type') }}</label>
                <select wire:model="absType" class="o-input">
                    <option value="absent">{{ __('Absent (unpaid)') }}</option>
                    <option value="sick">{{ __('Sick (paid)') }}</option>
                </select>
            </div>
            <button wire:click="addAbsence" class="o-btn-primary">{{ __('Add') }}</button>
        </div>
        <table class="min-w-full divide-y divide-chrome-100 text-sm">
            <tbody class="divide-y divide-chrome-100">
                @forelse ($absences as $a)
                    <tr wire:key="abs-{{ $a->id }}">
                        <td class="px-4 py-2 text-chrome-700">{{ $a->date->isoFormat('ddd, MMM D') }}</td>
                        <td class="px-4 py-2">
                            @if ($a->paid)
                                <span class="inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-700">{{ __('Sick (paid)') }}</span>
                            @else
                                <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Absent (unpaid)') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-end"><button wire:click="removeAbsence({{ $a->id }})" class="text-xs text-red-500 hover:underline">{{ __('remove') }}</button></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-xs text-chrome-400">{{ __('No absences this month.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

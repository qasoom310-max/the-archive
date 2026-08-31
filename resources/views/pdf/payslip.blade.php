@php
    use App\Erp\Money\Currencies;
    $money = fn ($v) => Currencies::format((float) $v);
    $hrs = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 12px; }
        h1 { font-size: 18px; margin: 0; }
        .muted { color: #6b7280; font-size: 11px; }
        .box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; margin-top: 14px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 6px 4px; }
        .label { color: #6b7280; }
        .right { text-align: right; }
        .total td { border-top: 2px solid #1f2937; font-weight: bold; font-size: 14px; padding-top: 10px; }
        .pos { color: #047857; }
        .neg { color: #b91c1c; }
        .head td { padding: 2px 4px; }
    </style>
</head>
<body>
    <h1>{{ __('Salary Slip') }}</h1>
    <p class="muted">{{ $monthLabel }} @if ($paidOn) · {{ __('Paid') }} {{ $paidOn->isoFormat('DD-MMM-YYYY') }} @endif</p>

    <div class="box">
        <table>
            <tr class="head"><td class="label" style="width:30%">{{ __('Employee') }}</td><td>{{ $employee->name }}</td></tr>
            @if ($employee->position)<tr class="head"><td class="label">{{ __('Position') }}</td><td>{{ $employee->position }}</td></tr>@endif
            @if ($employee->cpr)<tr class="head"><td class="label">{{ __('CPR / ID') }}</td><td>{{ $employee->cpr }}</td></tr>@endif
            <tr class="head"><td class="label">{{ __('Month') }}</td><td>{{ $monthLabel }}</td></tr>
        </table>
    </div>

    <div class="box">
        <table>
            <tr>
                <td class="label">{{ __('Basic salary') }}</td>
                <td class="right">{{ $money($calc['basic']) }}</td>
            </tr>
            <tr>
                <td class="label">
                    {{ __('Overtime') }}
                    <span class="muted">({{ $hrs($calc['ot_day_hours']) }}h ×1.2 + {{ $hrs($calc['ot_night_hours']) }}h ×1.5 @ {{ $money($calc['hourly_rate']) }}/h)</span>
                </td>
                <td class="right pos">+{{ $money($calc['overtime_pay']) }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Absence deduction') }} <span class="muted">({{ $calc['absence_days'] }} {{ __('unpaid day(s)') }})</span></td>
                <td class="right neg">−{{ $money($calc['absence_deduction']) }}</td>
            </tr>
            <tr class="total">
                <td>{{ __('Net salary') }}</td>
                <td class="right">{{ $money($calc['net']) }}</td>
            </tr>
        </table>
    </div>

    <p class="muted" style="margin-top:18px">
        {{ __('Overtime is paid per Bahrain rates: day 7 AM–7 PM ×1.2, night 7 PM–7 AM ×1.5. Hourly rate = basic ÷ 240.') }}<br>
        {{ __('Generated') }}: {{ $generatedAt->isoFormat('DD-MMM-YYYY HH:mm') }}
    </p>
</body>
</html>

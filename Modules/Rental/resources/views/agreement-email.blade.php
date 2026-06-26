@php /** @var \Modules\Rental\Models\RentalOrder $order */ @endphp
<div style="font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; color:#1f2937; font-size:14px; line-height:1.6;">
    <p>{{ __('Dear') }} {{ $order->customer?->name ?? __('Customer') }},</p>

    <p>{{ __('Please find your car hire agreement attached (:ref).', ['ref' => $order->reference]) }}</p>

    <p>
        {{ __('Car') }}: <strong>{{ $order->vehicle?->displayName() ?? '—' }}</strong><br>
        {{ __('Pick-up date') }}: <strong>{{ optional($order->start_date)->format('d-m-Y') }}</strong> ·
        {{ __('Return date') }}: <strong>{{ optional($order->end_date)->format('d-m-Y') }}</strong>
    </p>

    <p>{{ __('Kindly review, sign, and return it. Thank you for choosing us.') }}</p>

    <p style="color:#6b7280;">{{ $companyName }}</p>
</div>

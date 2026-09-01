{{-- Short on purpose: the receipt itself is attached, and the figure most
     people want is the one they already know they paid. --}}
<p>{{ __('Thank you — your payment has been received.') }}</p>

<p>
    {{ __('Receipt no.') }}: <strong>{{ $receipt->reference }}</strong><br>
    {{ __('Amount') }}: <strong>{{ \App\Erp\Views\ValueFormat::money((float) $receipt->amount) }}</strong>
    @if ($receipt->balance_after !== null && (float) $receipt->balance_after > 0)
        <br>{{ __('Balance still to pay') }}: <strong>{{ \App\Erp\Views\ValueFormat::money((float) $receipt->balance_after) }}</strong>
    @endif
</p>

<p>{{ __('Your receipt is attached.') }}</p>

<p>{{ $companyName }}</p>

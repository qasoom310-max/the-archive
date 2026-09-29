{{-- How to pay this invoice: who a cheque is made out to, and the bank account
     for a transfer. Printed under the totals of the limousine and rental
     invoices, like the block on the office's old printed invoice.

     It reads THIS database's own settings (Settings → General), so each
     business prints its own account — never hardcode a bank detail here, or
     every other business on the system tells its customers to pay Wanaan.

     Nothing prints until at least one bank detail is filled in. The cheque
     line uses the payee setting, falling back to the company name.

     Inline styles so it looks the same in every document that includes it. --}}
@php
    $bankName = trim((string) \App\Erp\Settings\Setting::get('company.bank_name', ''));
    $bankAccount = trim((string) \App\Erp\Settings\Setting::get('company.bank_account', ''));
    $bankIban = trim((string) \App\Erp\Settings\Setting::get('company.bank_iban', ''));
    $bankSwift = trim((string) \App\Erp\Settings\Setting::get('company.bank_swift', ''));
    $payee = trim((string) \App\Erp\Settings\Setting::get('company.bank_payee', ''));
    if ($payee === '') {
        $payee = trim((string) \App\Erp\Settings\Setting::get('company.name', ''));
    }

    $bankLines = array_filter([
        __('Bank name') => $bankName,
        __('Account no.') => $bankAccount,
        __('IBAN') => $bankIban,
        __('SWIFT') => $bankSwift,
    ], static fn (string $value): bool => $value !== '');
@endphp

@if ($bankLines !== [])
    <div data-bank-details style="margin-top:18px; padding:10px 12px; border:1px solid #e5e7eb; font-size:9.5px; color:#374151; line-height:1.6; page-break-inside:avoid">
        @if ($payee !== '')
            <div>{{ __('Please make all cheques payable to') }}: <b style="color:#111827">{{ $payee }}</b></div>
        @endif
        <div style="margin-top:2px">{{ __('Bank transfer payment to') }}:</div>
        @foreach ($bankLines as $label => $value)
            <div><b style="color:#111827">{{ $label }}:</b> {{ $value }}</div>
        @endforeach
    </div>
@endif

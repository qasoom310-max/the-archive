{{-- A quotation / invoice / receipt emailed to the customer
     (App\Mail\CustomerDocumentMail). Table layout + inline styles: mail
     programs ignore most CSS, Outlook most of all. --}}
@php
    $logoCid = null;
    if ($logo !== null && isset($message)) {
        $logoCid = $message->embedData($logo['data'], $logo['name'], $logo['mime']);
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document->subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Helvetica,Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">
                {{-- Header: the logo on white, a gold rule underneath. --}}
                <tr>
                    <td align="center" style="padding:28px 24px 20px;background:#ffffff;">
                        @if ($logoCid)
                            <img src="{{ $logoCid }}" alt="{{ $companyName }}" style="display:block;max-width:220px;max-height:90px;height:auto;border:0;">
                        @else
                            <div style="font-size:22px;font-weight:bold;color:#111827;">{{ $companyName }}</div>
                        @endif
                    </td>
                </tr>
                <tr><td style="height:5px;background:#FFC837;line-height:5px;font-size:0;">&nbsp;</td></tr>

                <tr>
                    <td style="padding:28px 32px 8px;">
                        <h1 style="margin:0 0 18px;font-size:20px;color:#111827;">{{ $document->heading }}</h1>
                        <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">
                            {{ __('Dear :name,', ['name' => $document->customerName !== '' ? $document->customerName : __('Customer')]) }}
                        </p>
                        @foreach ($document->paragraphs as $paragraph)
                            <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">{{ $paragraph }}</p>
                        @endforeach
                    </td>
                </tr>

                @if ($document->facts !== [])
                    <tr>
                        <td style="padding:6px 32px 10px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e5e7eb;border-radius:8px;">
                                @foreach ($document->facts as [$label, $value])
                                    <tr>
                                        <td style="padding:10px 14px;font-size:14px;color:#6b7280;{{ $loop->last ? '' : 'border-bottom:1px solid #f1f1f1;' }}">{{ $label }}</td>
                                        <td align="right" style="padding:10px 14px;font-size:14px;font-weight:bold;color:#111827;{{ $loop->last ? '' : 'border-bottom:1px solid #f1f1f1;' }}">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif

                @if ($attachmentNames !== [])
                    <tr>
                        <td style="padding:14px 32px 4px;">
                            <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">{{ __('Attached to this email:') }}</p>
                            @foreach ($attachmentNames as $file)
                                <p style="margin:0 0 4px;font-size:13px;color:#374151;">&#128206; {{ $file }}</p>
                            @endforeach
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:20px 32px 28px;">
                        <p style="margin:0 0 6px;font-size:15px;line-height:1.6;">{{ __('If you have any questions, simply reply to this email.') }}</p>
                        <p style="margin:18px 0 0;font-size:15px;line-height:1.6;">
                            {{ __('Kind regards,') }}<br>
                            <strong>{{ $companyName }}</strong>
                        </p>
                    </td>
                </tr>

                @php
                    $phone = trim((string) \App\Erp\Settings\Setting::get('company.phone', ''));
                    $site = trim((string) \App\Erp\Settings\Setting::get('company.website', ''));
                    $address = trim((string) \App\Erp\Settings\Setting::get('company.address', ''));
                @endphp
                <tr>
                    <td align="center" style="padding:18px 24px;background:#111827;color:#d1d5db;font-size:12px;line-height:1.7;">
                        <strong style="color:#FFC837;">{{ $companyName }}</strong><br>
                        @if ($phone !== '') {{ $phone }} @endif
                        @if ($phone !== '' && $site !== '') &nbsp;·&nbsp; @endif
                        @if ($site !== '') {{ $site }} @endif
                        @if ($address !== '')<br>{{ $address }}@endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

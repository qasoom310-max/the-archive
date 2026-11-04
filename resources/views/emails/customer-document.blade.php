{{-- Every email a customer receives (App\Mail\CustomerDocumentMail and the
     Limousine / Rent A Car send buttons). The wording, colours, signature and
     footer come from Settings → Customer emails (App\Erp\Mail\EmailTemplates).
     Table layout + inline styles: mail programs ignore most CSS, Outlook most
     of all. --}}
@php
    /** @var \App\Erp\Mail\CustomerDocument $document */
    $layout = $layout ?? app(\App\Erp\Mail\EmailTemplates::class)->layout();
    $vars = app(\App\Erp\Mail\EmailTemplates::class)->withCommon(['customer' => $document->customerName !== '' ? $document->customerName : 'Customer']);
    $fill = static fn (string $text): string => app(\App\Erp\Mail\EmailTemplates::class)->fill($text, $vars);

    $accent = $layout['accent'];
    $accentInk = \App\Erp\Mail\EmailTemplates::inkOn($accent);
    $footerBg = $layout['footer_bg'];
    $footerDark = \App\Erp\Mail\EmailTemplates::inkOn($footerBg) === '#ffffff';
    $footerInk = $footerDark ? '#d1d5db' : '#374151';
    $footerStrong = $footerDark ? $accent : '#111827';

    $logoSrc = $logoSrc ?? null;
    if ($logoSrc === null && $layout['show_logo'] !== '0' && ($logo ?? null) !== null && isset($message)) {
        $logoSrc = $message->embedData($logo['data'], $logo['name'], $logo['mime']);
    }
    if ($layout['show_logo'] === '0') {
        $logoSrc = null;
    }

    $greeting = trim($fill($layout['greeting']));
    $closing = \App\Erp\Mail\EmailTemplates::paragraphs($fill($layout['closing']));
    $signature = \App\Erp\Mail\EmailTemplates::lines($fill($layout['signature']));
    $footer = \App\Erp\Mail\EmailTemplates::lines($fill($layout['footer']));
    $smallPrint = \App\Erp\Mail\EmailTemplates::paragraphs($fill($layout['small_print']));
    $p = 'margin:0 0 14px;font-size:15px;line-height:1.6;color:#1f2937;';
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
                {{-- Header: the logo on white, an accent rule underneath. --}}
                <tr>
                    <td align="center" style="padding:28px 24px 20px;background:#ffffff;">
                        @if ($logoSrc)
                            <img src="{{ $logoSrc }}" alt="{{ $companyName }}" style="display:block;max-width:220px;max-height:90px;height:auto;border:0;">
                        @else
                            <div style="font-size:22px;font-weight:bold;color:#111827;">{{ $companyName }}</div>
                        @endif
                    </td>
                </tr>
                <tr><td style="height:5px;background:{{ $accent }};line-height:5px;font-size:0;">&nbsp;</td></tr>

                <tr>
                    <td style="padding:28px 32px 8px;">
                        @if (trim($document->heading) !== '')
                            <h1 style="margin:0 0 18px;font-size:20px;color:#111827;">{{ $document->heading }}</h1>
                        @endif
                        @if ($greeting !== '')
                            <p style="{{ $p }}">{!! \App\Erp\Mail\EmailTemplates::html($greeting) !!}</p>
                        @endif
                        @foreach ($document->paragraphs as $paragraph)
                            <p style="{{ $p }}">{!! \App\Erp\Mail\EmailTemplates::html($paragraph) !!}</p>
                        @endforeach
                    </td>
                </tr>

                @if ($document->callout !== null)
                    <tr>
                        <td style="padding:6px 32px 14px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:2px dashed {{ $accent }};border-radius:8px;">
                                <tr>
                                    <td align="center" style="padding:18px 14px;">
                                        <div style="font-size:26px;font-weight:bold;letter-spacing:2px;color:#111827;">{{ $document->callout['title'] }}</div>
                                        @foreach ($document->callout['lines'] as $line)
                                            <div style="margin-top:6px;font-size:14px;color:#374151;">{{ $line }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

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

                @if ($document->button !== null)
                    <tr>
                        <td align="center" style="padding:16px 32px 6px;">
                            <a href="{{ $document->button['url'] }}" style="display:inline-block;padding:12px 28px;border-radius:8px;background:{{ $accent }};color:{{ $accentInk }};font-size:15px;font-weight:bold;text-decoration:none;">{{ $document->button['label'] }}</a>
                            <p style="margin:12px 0 0;font-size:12px;line-height:1.5;color:#6b7280;">
                                If the button does not work, copy this link into your browser:<br>
                                <a href="{{ $document->button['url'] }}" style="color:#374151;word-break:break-all;">{{ $document->button['url'] }}</a>
                            </p>
                        </td>
                    </tr>
                @endif

                @if ($document->outro !== [])
                    <tr>
                        <td style="padding:14px 32px 0;">
                            @foreach ($document->outro as $paragraph)
                                <p style="{{ $p }}">{!! \App\Erp\Mail\EmailTemplates::html($paragraph) !!}</p>
                            @endforeach
                        </td>
                    </tr>
                @endif

                @if ($attachmentNames !== [])
                    <tr>
                        <td style="padding:6px 32px 4px;">
                            <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">Attached to this email:</p>
                            @foreach ($attachmentNames as $file)
                                <p style="margin:0 0 4px;font-size:13px;color:#374151;">&#128206; {{ $file }}</p>
                            @endforeach
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:20px 32px 28px;">
                        @foreach ($closing as $paragraph)
                            <p style="margin:0 0 6px;font-size:15px;line-height:1.6;">{!! \App\Erp\Mail\EmailTemplates::html($paragraph) !!}</p>
                        @endforeach
                        @if ($signature !== [])
                            <p style="margin:18px 0 0;font-size:15px;line-height:1.6;">
                                @foreach ($signature as $line)
                                    {!! \App\Erp\Mail\EmailTemplates::html($line) !!}@if (! $loop->last)<br>@endif
                                @endforeach
                            </p>
                        @endif
                    </td>
                </tr>

                @if ($footer !== [] || $smallPrint !== [])
                    <tr>
                        <td align="center" style="padding:18px 24px;background:{{ $footerBg }};color:{{ $footerInk }};font-size:12px;line-height:1.7;">
                            @foreach ($footer as $line)
                                {!! str_replace('<strong>', '<strong style="color:' . e($footerStrong) . ';">', \App\Erp\Mail\EmailTemplates::html($line)) !!}@if (! $loop->last)<br>@endif
                            @endforeach
                            @foreach ($smallPrint as $paragraph)
                                <p style="margin:10px 0 0;font-size:11px;line-height:1.5;opacity:0.8;">{!! \App\Erp\Mail\EmailTemplates::html($paragraph) !!}</p>
                            @endforeach
                        </td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>
</body>
</html>

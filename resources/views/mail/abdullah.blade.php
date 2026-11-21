<!doctype html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;">
    <tr><td style="padding:20px 24px;border-bottom:3px solid #f5ef1a;">
        <strong style="font-size:16px;">{{ __('Abdullah') }}</strong>
        <span style="color:#71717a;font-size:12px;"> · {{ __('ERP assistant') }}</span>
    </td></tr>
    <tr><td style="padding:20px 24px;font-size:15px;line-height:1.6;white-space:pre-line;">{{ $text }}</td></tr>
    @if ($rows !== [])
        <tr><td style="padding:0 24px 20px;">
            <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
                <tr style="background:#f4f4f5;text-align:{{ $rtl ? 'right' : 'left' }};">
                    <th>{{ __('Trip') }}</th><th>{{ __('Pick-up') }}</th><th>{{ __('Missing') }}</th><th></th>
                </tr>
                @foreach ($rows as $row)
                    <tr style="border-top:1px solid #e4e4e7;">
                        <td dir="ltr">{{ $row['reference'] }}</td>
                        <td dir="ltr">{{ $row['time'] }}</td>
                        <td>{{ $row['missing'] }}</td>
                        <td><a href="{{ $row['url'] }}" style="color:#854d0e;">{{ __('Open') }}</a></td>
                    </tr>
                @endforeach
            </table>
        </td></tr>
    @endif
    <tr><td style="padding:14px 24px;color:#a1a1aa;font-size:11px;">{{ __('Abdullah stops when every trip has its car and driver, or when you answer him in the ERP.') }}</td></tr>
</table>
</body>
</html>

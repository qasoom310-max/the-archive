{{-- The terms PDF attached to every document emailed to a customer
     (App\Erp\Mail\CustomerMailer::termsPdf). The text is the database's own
     `company.customer_terms` setting, written as Markdown. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Terms & Conditions') }}</title>
    <style>
        @page { margin: 1.4cm 1.6cm 60px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; line-height: 1.55; }
        .band { height: 6px; background: #FFC837; margin-bottom: 18px; }
        .head { width: 100%; margin-bottom: 6px; }
        .head td { vertical-align: middle; }
        .company { font-size: 15px; font-weight: bold; color: #111827; text-align: right; }
        h1 { font-size: 20px; color: #111827; margin: 6px 0 10px; }
        h2 { font-size: 13px; color: #111827; margin: 18px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #e5e7eb; }
        h3 { font-size: 11.5px; margin: 12px 0 4px; }
        p { margin: 0 0 7px; }
        ul, ol { margin: 0 0 8px; padding-left: 18px; }
        li { margin-bottom: 3px; }
        strong { color: #111827; }
    </style>
</head>
<body>
    <div class="band"></div>
    <table class="head" cellspacing="0" cellpadding="0">
        <tr>
            <td>
                @if ($logoSrc)
                    <img src="{{ $logoSrc }}" alt="" style="max-height: 60px; max-width: 200px;">
                @endif
            </td>
            <td class="company">{{ $companyName }}</td>
        </tr>
    </table>

    {!! $html !!}

    <x-document-footer />
</body>
</html>

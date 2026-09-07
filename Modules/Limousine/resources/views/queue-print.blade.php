{{-- Printable / PDF rendering of the bookings queue.

     Shared by the Print view and the PDF download, so both look the same. The
     CSS is inlined and plain: DomPDF understands almost no modern CSS, and this
     page is also what comes out of a browser's Print dialog. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Bookings') }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 1.2cm 1.2cm 60px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111827; margin: 16px; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .meta { font-size: 9px; color: #6b7280; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 4px 5px; text-align: start; vertical-align: top; }
        th { background: #f3f4f6; font-size: 8px; text-transform: uppercase; letter-spacing: .03em; }
        td.num, th.num { text-align: end; }
        tr:nth-child(even) td { background: #fafafa; }
        .empty { padding: 24px; text-align: center; color: #6b7280; }
        @media print { body { margin: 0; } .noprint { display: none; } }
    </style>
</head>
<body>
    <h1>{{ __('Bookings') }}</h1>
    <div class="meta">
        {{ __('Company') }}: {{ \App\Erp\Settings\Setting::get('company.name', '') }}
        · {{ now()->isoFormat('DD-MMM-YY HH:mm') }}
        · {{ count($rows) }} {{ __('rows') }}
    </div>

    @if (! $forPdf)
        {{-- Browser print only: fires once, and is hidden on the printed page. --}}
        <script>window.addEventListener('load', () => window.print());</script>
    @endif

    @if (count($rows) === 0)
        <p class="empty">{{ __('No bookings found.') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($headings as $key => $label)
                        <th class="{{ in_array($key, ['amount', 'received', 'balance'], true) ? 'num' : '' }}">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($headings as $key => $label)
                            @php $money = in_array($key, ['amount', 'received', 'balance'], true); @endphp
                            <td class="{{ $money ? 'num' : '' }}">
                                @if ($money)
                                    {{ \App\Erp\Views\ValueFormat::money((float) $row[$key]) }}
                                @elseif (in_array($key, ['status', 'payment'], true))
                                    {{ __(ucfirst((string) $row[$key])) }}
                                @else
                                    {{ $row[$key] }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <x-document-footer :fixed="$forPdf" />
</body>
</html>

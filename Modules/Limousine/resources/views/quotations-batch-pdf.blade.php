{{-- Several ticked quotations downloaded as ONE PDF — the toolbar's "PDF"
     button on the quotations list, when rows are selected. Each quotation
     keeps its own full page in the exact design of `quotation-pdf.blade.php`
     (they share `partials/quotation-body.blade.php`), one after another; a
     single ticked row is the same page a lone quotation download produces.

     Table-based layout with inline styles because DomPDF supports neither
     flexbox nor grid — same reasoning and the same <style> block as the
     single-quotation document (kept in sync by hand; both stay small). The
     footer is declared ONCE — `position: fixed` repeats it on every physical
     page DomPDF paginates, including the manual page breaks below. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Quotations') }}</title>
    <style>
        @page { margin: 30px 34px 60px; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; font-size: 10.5px; line-height: 1.55; }

        .quotation-page { page-break-before: always; }

        .topbar { background: #FFC837; height: 40px; margin: -30px -34px 20px -34px; }
        .sheet { padding: 0 0 4px; }

        table.head-meta { width: 100%; }
        table.head-meta td { vertical-align: top; }
        .doc-title { font-size: 38px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; color: #111827; margin: 0; }
        .meta-cell { text-align: right; padding-left: 18px; width: 92px; }
        .meta-label { font-size: 7.5px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; }
        .meta-value { font-size: 10px; color: #111827; margin-top: 3px; }

        .rule { border-top: 1px solid #d1d5db; margin: 16px 0 20px; }

        .bill-label { font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin-bottom: 4px; }
        .bill-name { font-weight: bold; font-size: 11px; color: #111827; }
        .bill-line { color: #374151; margin-top: 2px; }
        .bill-logo { max-height: 26px; margin-bottom: 4px; }

        table.items { width: 100%; margin-top: 26px; }
        table.items th {
            text-align: left; font-size: 9.5px; font-weight: bold; color: #111827;
            padding: 0 6px 8px; border-bottom: 1px solid #9ca3af;
        }
        table.items th.num, table.items td.num { text-align: right; }
        table.items td { padding: 9px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }

        .requested-by { margin-top: 10px; font-size: 9.5px; color: #374151; }
        .requested-by b { color: #111827; }

        table.summary { width: 240px; margin-top: 12px; }
        table.summary td { padding: 5px 0; font-size: 10px; }
        table.summary td.k { color: #374151; }
        table.summary td.v { text-align: right; }
        table.summary tr.final td { border-top: 1px solid #9ca3af; font-weight: bold; font-size: 12px; padding-top: 9px; }

        .doc-note { margin-top: 26px; font-size: 9.5px; color: #374151; line-height: 1.6; }
        .doc-note b { color: #111827; }
    </style>
</head>
<body>

@foreach ($quotations as $quoteData)
    <div @if (! $loop->first) class="quotation-page" @endif>
        @include('limousine::partials.quotation-body', $quoteData)
    </div>
@endforeach

<x-document-footer />

</body>
</html>

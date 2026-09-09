{{-- The customer's bill for a rental — same reference-template style as the
     Limousine invoice/receipt/quotation: a solid #FFC837 band across the top,
     a large plain "INVOICE" title with issue/due date + invoice number +
     VAT registration number in small label/value columns beside it, a
     two-column Bill from / Bill to, a plain-ruled item table, and a
     right-aligned totals box with the final row bold above a top rule.

     Table-based layout with inline styles because DomPDF supports neither
     flexbox nor grid. This document does NOT include the shared
     `<x-pdf-styles />` component — same reasoning as
     `Modules/Limousine/resources/views/invoice-pdf.blade.php`, whose <style>
     block this mirrors exactly. The BODY markup is shared with
     `invoices-batch-pdf.blade.php` via `partials/invoice-body.blade.php`
     (several ticked invoices downloaded as one PDF, one per page) — keep
     both in sync when this changes.

     The BALANCE is still the headline, not the total — same reasoning as the
     Limousine invoice. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice') }} {{ $reference }}</title>
    <style>
        @page { margin: 30px 34px 60px; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; font-size: 10.5px; line-height: 1.55; }

        {{-- Bled to the true page edge via NEGATIVE margins matching the
             @page margin above, rather than zeroing the page margin itself —
             the shared <x-document-footer /> is `position: fixed`, and DomPDF
             computes that against the page's margin box, so a zeroed @page
             margin silently broke the footer (it stopped rendering at all). --}}
        .topbar { background: #FFC837; height: 40px; margin: -30px -34px 20px -34px; }
        .sheet { padding: 0 0 4px; }

        table.head-meta { width: 100%; }
        table.head-meta td { vertical-align: top; }
        .doc-title { font-size: 38px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; color: #111827; margin: 0; }
        .meta-cell { text-align: right; padding-left: 18px; width: 92px; }
        .meta-label { font-size: 7.5px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; }
        .meta-value { font-size: 10px; color: #111827; margin-top: 3px; }

        .doc-badge {
            display: inline-block; margin-top: 8px; padding: 3px 11px; font-size: 8px; font-weight: bold;
            text-transform: uppercase; letter-spacing: .5px; border-radius: 9px; color: #ffffff;
        }
        .doc-badge-paid, .doc-badge-settled { background: #16a34a; }
        .doc-badge-partial { background: #FFC837; color: #111827; }
        .doc-badge-unpaid, .doc-badge-void { background: #dc2626; }

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

        table.summary { width: 240px; margin-top: 12px; }
        table.summary td { padding: 5px 0; font-size: 10px; }
        table.summary td.k { color: #374151; }
        table.summary td.v { text-align: right; }
        table.summary tr.final td { border-top: 1px solid #9ca3af; font-weight: bold; font-size: 12px; padding-top: 9px; }
        .owed { color: #b91c1c; }
        .settled { color: #15803d; }

        .doc-note { margin-top: 26px; font-size: 9.5px; color: #374151; line-height: 1.6; }
        .doc-note b { color: #111827; }
    </style>
</head>
<body>

@include('rental::partials.invoice-body')

<x-document-footer />

</body>
</html>

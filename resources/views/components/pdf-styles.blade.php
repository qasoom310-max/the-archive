{{-- Shared visual language for every Rental & Limousine PDF — so an invoice,
     quotation, receipt, statement, coupon, service order and agreement all
     read as one coherent document family instead of seven different ones.

     Table-based layout throughout (DomPDF supports neither flexbox nor grid).
     Each host document keeps its OWN `<style>` block for the `@page` rule
     (pinned verbatim by DocumentFooterTest) and any document-specific
     overrides; this component is included FIRST in `<head>` so the cascade
     lets a later, equally-specific host rule win where a document needs to
     differ (e.g. a narrower `.k` label column). --}}
<style>
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; line-height: 1.5; }
    table { border-collapse: collapse; }

    .muted { color: #6b7280; }
    .faint { color: #9ca3af; }
    .owed { color: #b91c1c; font-weight: bold; }
    .settled { color: #15803d; font-weight: bold; }

    /* Letterhead: logo / brand mark on the left, document title + reference
       on the right — the two facts anyone opening the page reaches for
       first. Replaces the old centred, underlined "<h1>Invoice</h1>". */
    table.doc-head { width: 100%; }
    table.doc-head td { vertical-align: top; padding: 0; }
    .doc-brand-name { font-size: 13.5px; font-weight: bold; color: #0f172a; margin-top: 5px; }
    .doc-brand-fallback {
        display: inline-block; background: #0f172a; color: #f5ef1a;
        padding: 9px 18px; font-size: 18px; font-weight: bold; letter-spacing: 1px;
        border-radius: 3px;
    }
    .doc-title-block { text-align: right; }
    .doc-title { font-size: 21px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #0f172a; margin: 0 0 3px; }
    .doc-ref { font-size: 10.5px; color: #4b5563; }
    .doc-ref b { color: #0f172a; }
    .doc-sub { font-size: 8.5px; color: #9ca3af; margin-top: 2px; }

    .doc-accent { border-top: 2.5px solid #0f172a; margin: 10px 0 14px; font-size: 0; line-height: 0; }

    /* Status pill next to a title (Paid / Partial / Unpaid, ...). */
    .doc-badge {
        display: inline-block; padding: 3px 10px; font-size: 8px; font-weight: bold;
        text-transform: uppercase; letter-spacing: .4px; border-radius: 9px; margin-top: 5px;
    }
    .doc-badge-paid, .doc-badge-settled { background: #f0fdf4; color: #15803d; }
    .doc-badge-partial { background: #eff6ff; color: #1d4ed8; }
    .doc-badge-unpaid, .doc-badge-void { background: #fef2f2; color: #b91c1c; }

    /* Label/value meta blocks ("Billed to", "For booking", "Customer", ...). */
    table.doc-meta { width: 100%; margin-top: 4px; }
    table.doc-meta td { padding: 4px 4px; vertical-align: top; border-bottom: 1px solid #f1f5f9; }
    table.doc-meta .k { font-weight: bold; color: #4b5563; width: 130px; }
    table.doc-meta .v { color: #111827; }

    /* Line-item / trip / ledger tables. */
    table.doc-table { width: 100%; }
    table.doc-table th {
        text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .4px;
        color: #6b7280; background: #f8fafc; border-bottom: 1.5px solid #0f172a; padding: 6px 5px;
    }
    table.doc-table td { padding: 7px 5px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    table.doc-table tbody tr:nth-child(even) td { background: #fafbfc; }
    .num { text-align: right; white-space: nowrap; }

    /* Totals summary, right-aligned under the item table. */
    table.doc-totals { width: 230px; }
    table.doc-totals td { padding: 4px 6px; }
    table.doc-totals .k { text-align: right; color: #6b7280; }
    table.doc-totals .v { text-align: right; width: 100px; font-weight: bold; color: #111827; }
    table.doc-totals .grand td { border-top: 1.5px solid #0f172a; font-size: 13px; padding-top: 8px; }

    /* Boxed headline figure — amount received, coupon balance, ... */
    .doc-figure-box {
        border: 1px solid #e5e7eb; background: #f8fafc; border-radius: 6px;
        padding: 14px 18px; text-align: center; margin: 16px 0;
    }
    .doc-figure-label { font-size: 8.5px; letter-spacing: 1px; text-transform: uppercase; color: #9ca3af; }
    .doc-figure { font-size: 26px; font-weight: bold; color: #0f172a; }

    /* Signature / sign-off strip. */
    table.doc-sign { width: 100%; margin-top: 30px; }
    table.doc-sign td { padding-top: 26px; border-top: 1px solid #d1d5db; font-size: 9px; color: #6b7280; }

    .doc-note { margin-top: 18px; font-size: 9px; color: #4b5563; line-height: 1.6; }
</style>

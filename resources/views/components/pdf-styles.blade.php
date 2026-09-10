{{-- Shared visual language for every Rental & Limousine PDF — so an invoice,
     quotation, receipt, statement, coupon, service order and agreement all
     read as one coherent, premium document family instead of seven different
     ones.

     Table-based layout throughout (DomPDF supports neither flexbox nor grid).
     Each host document keeps its OWN `<style>` block for the `@page` rule
     (pinned verbatim by DocumentFooterTest) and any document-specific
     overrides; this component is included FIRST in `<head>` so the cascade
     lets a later, equally-specific host rule win where a document needs to
     differ (e.g. a narrower `.k` label column). --}}
<style>
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1e293b; line-height: 1.55; }
    table { border-collapse: collapse; }

    .muted { color: #64748b; }
    .faint { color: #94a3b8; }
    .owed { color: #b91c1c; font-weight: bold; }
    .settled { color: #15803d; font-weight: bold; }

    /* Letterhead: a solid ink-navy band carries the brand mark on the left
       (in its own white "chip" so any logo, light or dark, always sits on a
       clean plate) and the document title in gold on the right — the two
       facts anyone opening the page reaches for first. Replaces the old
       centred, underlined "<h1>Invoice</h1>" AND the earlier plain
       white-background letterhead. */
    table.doc-head { width: 100%; background: #0b1220; border-radius: 7px; }
    table.doc-head td { vertical-align: middle; padding: 15px 20px; }
    .doc-logo-chip {
        display: inline-block; background: #ffffff; padding: 7px 14px;
        border-radius: 5px; line-height: 0;
    }
    .doc-brand-name { font-size: 13.5px; font-weight: bold; color: #0b1220; }
    .doc-brand-fallback {
        display: inline-block; background: #ffffff; color: #0b1220;
        padding: 10px 18px; font-size: 17px; font-weight: bold; letter-spacing: .8px;
        border-radius: 5px;
    }
    .doc-title-block { text-align: right; }
    .doc-title { font-size: 22px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; color: #eab308; margin: 0 0 4px; }
    .doc-ref { font-size: 10px; color: #cbd5e1; }
    .doc-ref b { color: #ffffff; }
    .doc-sub { font-size: 8.5px; color: #94a3b8; margin-top: 3px; }

    /* Slim gold seam under the letterhead band — the accent colour that
       recurs through headings, dividers and the totals block below. */
    .doc-accent { height: 3px; background: #eab308; border-radius: 2px; margin: 0 0 16px; font-size: 0; line-height: 0; }

    /* Status pill next to a title (Paid / Partial / Unpaid, ...) — solid
       fill + white text so it reads clearly against the dark letterhead. */
    .doc-badge {
        display: inline-block; padding: 4px 12px; font-size: 8px; font-weight: bold;
        text-transform: uppercase; letter-spacing: .6px; border-radius: 10px; margin-top: 6px;
        color: #ffffff;
    }
    .doc-badge-paid, .doc-badge-settled { background: #16a34a; }
    .doc-badge-partial { background: #2563eb; }
    .doc-badge-unpaid, .doc-badge-void { background: #dc2626; }

    /* Label/value meta blocks ("Billed to", "For booking", "Customer", ...)
       — a soft card with a gold flag on the left, not just bare rows. */
    table.doc-meta { width: 100%; margin-top: 14px; background: #f8fafc; border-radius: 6px; border-left: 3px solid #eab308; }
    table.doc-meta td { padding: 6px 14px; vertical-align: top; border-bottom: 1px solid #eef2f7; }
    table.doc-meta tr:last-child td { border-bottom: none; }
    table.doc-meta .k { font-weight: bold; color: #0b1220; width: 130px; font-size: 9px; text-transform: uppercase; letter-spacing: .4px; }
    table.doc-meta .v { color: #1e293b; }

    /* Line-item / trip / ledger tables — dark header with gold caps,
       zebra body, and a heavier ink rule closing the table off. */
    table.doc-table { width: 100%; }
    table.doc-table thead tr { background: #0b1220; }
    table.doc-table th {
        text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px;
        color: #eab308; padding: 8px 6px;
    }
    table.doc-table td { padding: 8px 6px; border-bottom: 1px solid #eef2f7; vertical-align: top; }
    table.doc-table tbody tr:nth-child(even) td { background: #f8fafc; }
    table.doc-table tbody tr:last-child td { border-bottom: 1.5px solid #0b1220; }
    .num { text-align: right; white-space: nowrap; }

    /* Totals summary, right-aligned under the item table, boxed like a
       receipt stub with a gold rule ahead of the grand total. */
    table.doc-totals { width: 235px; border: 1px solid #e2e8f0; border-radius: 6px; }
    table.doc-totals td { padding: 6px 12px; }
    table.doc-totals .k { text-align: right; color: #64748b; font-size: 9.5px; }
    table.doc-totals .v { text-align: right; width: 100px; font-weight: bold; color: #0b1220; }
    table.doc-totals .grand td { border-top: 2px solid #eab308; font-size: 13.5px; font-weight: bold; padding-top: 9px; color: #0b1220; }
    table.doc-totals td.v.owed { color: #b91c1c; background: #fef2f2; border-radius: 4px; }
    table.doc-totals td.v.settled { color: #15803d; background: #f0fdf4; border-radius: 4px; }

    /* Boxed headline figure — amount received, coupon balance, ... rendered
       as a dark "ticket stub" so the one number that matters is unmissable. */
    .doc-figure-box {
        background: #0b1220; border-radius: 8px;
        padding: 18px 20px; text-align: center; margin: 18px 0;
    }
    .doc-figure-label { font-size: 8.5px; letter-spacing: 1.6px; text-transform: uppercase; color: #94a3b8; }
    .doc-figure { font-size: 30px; font-weight: bold; color: #eab308; margin-top: 3px; }
    .doc-figure-box .owed { color: #f87171; }
    .doc-figure-box .settled { color: #4ade80; }

    /* Signature / sign-off strip — a fine dotted rule with a small-caps
       label, the physical signing space sitting above it. */
    table.doc-sign { width: 100%; margin-top: 30px; }
    table.doc-sign td {
        padding-top: 8px; border-top: 1.3px dotted #94a3b8;
        font-size: 8.5px; text-transform: uppercase; letter-spacing: .6px; color: #64748b;
    }

    /* Callout note — Terms, Notes, Comments — a tinted card, not bare text. */
    .doc-note {
        margin-top: 18px; padding: 10px 14px; background: #f8fafc; border-left: 3px solid #eab308;
        border-radius: 4px; font-size: 9px; color: #475569; line-height: 1.65;
    }
</style>

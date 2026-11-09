{{-- The receipt's styles, shared by receipt-pdf (one receipt) and
     receipts-batch-pdf (several ticked receipts, one page each). --}}
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; font-size: 10.5px; line-height: 1.55; }

        {{-- Bled to the true page edge via NEGATIVE margins matching the
             @page margin above, exactly like the invoice — zeroing the page
             margin instead would silently break the fixed-position
             <x-document-footer /> below. --}}
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
        table.items td.trips { font-size: 9.5px; color: #374151; }
        /* The trips of one payment: a dashed rule between them, the solid one under the last. */
        table.items tr.trip-row td { border-bottom: 1px dashed #e5e7eb; padding-bottom: 6px; }
        table.items tr.trip-row + tr td { padding-top: 6px; }
        table.items tr { page-break-inside: avoid; }
        table.items td.trips b { color: #111827; }
        table.items .trip-car { color: #6b7280; }

        table.summary { width: 240px; margin-top: 12px; }
        table.summary td { padding: 5px 0; font-size: 10px; }
        table.summary td.k { color: #374151; }
        table.summary td.v { text-align: right; }
        table.summary tr.final td { border-top: 1px solid #9ca3af; font-weight: bold; font-size: 12px; padding-top: 9px; }
        .owed { color: #b91c1c; }
        .settled { color: #15803d; }

        .doc-note { margin-top: 26px; font-size: 9.5px; color: #374151; line-height: 1.6; }
        .doc-note b { color: #111827; }

        {{-- The signature strip of the pad this replaced: a slot to sign, a
             slot to stamp, and — when we know it — the name of whoever
             raised it. A receipt is our acknowledgement that the money
             arrived, so the second slot is the company stamp, not the
             customer's signature — the customer is not attesting to
             anything by being paid up. --}}
        table.sign-row { width: 100%; margin-top: 48px; }
        table.sign-row td { padding-top: 6px; border-top: 1px solid #9ca3af; font-size: 8.5px; text-transform: uppercase; letter-spacing: .3px; color: #6b7280; }
        table.sign-row td.prepared { text-align: right; text-transform: none; letter-spacing: normal; }
        table.sign-row td.prepared b { color: #111827; }

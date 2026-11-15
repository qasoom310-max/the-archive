<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Services\LimoInvoiceRows;
use Modules\Limousine\Services\LimoInvoicePdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the invoices list: CSV, Excel, PDF and a printable view — all
 * four from the SAME filtered rows as {@see LimoInvoiceRows} (tab, issue-date
 * window and search all ride along).
 *
 * "PDF" is the exception: when rows are ticked, it does not export a data
 * table of them — it downloads the actual invoice document(s) those rows
 * represent (one page each, {@see LimoInvoicePdf}), same as the download
 * icon on a single row. Untick everything and it is a table of the whole
 * tab.
 *
 * Every table here (CSV, Excel, Print, the unticked PDF) carries the printed
 * invoice's own columns, one row per trip, so a downloaded sheet reads like
 * the document: Sl, Ref. #, Service, Car Type, From, To, Service date,
 * Company ref., Pax name, Total, then the invoice and customer.
 */
final class LimoInvoiceExportController
{
    use GuardsExport;

    public function __construct(
        private readonly LimoInvoiceRows $rows,
        private readonly TabularRenderer $renderer,
        private readonly LimoInvoicePdf $invoicePdf,
    ) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.invoice');

        [$headings, $rows] = $this->table($request);

        return $this->renderer->csv($headings, $rows, $this->exportFilename('limousine-invoices'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.invoice');

        [$headings, $rows] = $this->table($request);

        return $this->renderer->excel($headings, $rows, $this->exportFilename('limousine-invoices'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('limousine.invoice');

        $ids = $this->ids($request);
        if ($ids !== [] && ! $request->boolean('live')) {
            return $this->invoiceDocuments($ids);
        }

        [$headings, $rows] = $this->table($request);

        return $this->renderer->pdf($headings, $rows, __('Invoices'), $this->exportFilename('limousine-invoices'));
    }

    /**
     * The ticked rows' own invoice documents, one page each, in ticked order.
     *
     * @param  list<int>  $ids
     */
    private function invoiceDocuments(array $ids): Response
    {
        $order = array_flip($ids);

        $invoices = LimoInvoice::query()
            ->with(['customer', 'booking.legs', 'quotation.legs'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(static fn (LimoInvoice $invoice): int => $order[$invoice->id] ?? PHP_INT_MAX)
            ->values();

        $first = $invoices->first();
        if ($first === null) {
            abort(404);
        }

        if ($invoices->count() === 1) {
            return response($this->invoicePdf->render($first), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $this->invoicePdf->filename($first) . '"',
            ]);
        }

        return response($this->invoicePdf->renderMany($invoices), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->invoicePdf->filenameForMany($invoices->count()) . '"',
        ]);
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('limousine.invoice');

        [$headings, $rows] = $this->table($request);

        return $this->renderer->print($headings, $rows, __('Invoices'));
    }

    /**
     * The sheet: the printed invoice's own columns, one row per trip, so the
     * Excel reads like the PDF. "Live entry data" stays one row per invoice:
     * it is a record-level backup, not a document.
     *
     * @return array{0: array<string, string>, 1: list<array<string, string>>}
     */
    private function table(Request $request): array
    {
        if ($request->boolean('live')) {
            return [$this->rows->headings(), $this->rows->all('all', '', '', '', [], true)];
        }

        return [$this->rows->lineHeadings(), $this->rows->lines(
            (string) $request->query('tab', 'all'),
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
            (string) $request->query('q', ''),
            $this->ids($request),
        )];
    }

    /**
     * The ticked rows, as `?ids=3,7,12`; empty (or junk) = the whole tab.
     *
     * @return list<int>
     */
    private function ids(Request $request): array
    {
        $raw = $request->query('ids');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter(explode(',', $raw), 'is_numeric'))));
    }
}

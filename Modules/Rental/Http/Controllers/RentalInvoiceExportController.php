<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Services\RentalInvoicePdf;
use Modules\Rental\Services\RentalInvoiceRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the invoices list: CSV, Excel, PDF and a printable view — all
 * four from the SAME filtered rows as {@see RentalInvoiceRows}, so a download
 * is always of the tab the office was actually looking at.
 *
 * "PDF" is the exception: when rows are ticked, it does not export a data
 * table of them — it downloads the actual invoice document(s) those rows
 * represent (one page each, {@see RentalInvoicePdf}), same as the download
 * icon on a single row. Untick everything and it goes back to the plain
 * tabular report of the whole tab, exactly like CSV/Excel/Print still do.
 */
final class RentalInvoiceExportController
{
    use GuardsExport;

    public function __construct(
        private readonly RentalInvoiceRows $rows,
        private readonly TabularRenderer $renderer,
        private readonly RentalInvoicePdf $invoicePdf,
    ) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('rental.invoice');

        return $this->renderer->csv($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request)), $this->exportFilename('rental-invoices'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('rental.invoice');

        return $this->renderer->excel($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request)), $this->exportFilename('rental-invoices'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('rental.invoice');

        $ids = $this->ids($request);
        if ($ids !== []) {
            return $this->invoiceDocuments($ids);
        }

        return $this->renderer->pdf($this->rows->headings(), $this->rows->all($this->tab($request), $ids), __('Invoices'), $this->exportFilename('rental-invoices'));
    }

    /**
     * The ticked rows' own invoice documents, one page each, in ticked order.
     *
     * @param  list<int>  $ids
     */
    private function invoiceDocuments(array $ids): Response
    {
        $order = array_flip($ids);

        $invoices = RentalInvoice::query()
            ->with(['customer', 'order.vehicle'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(static fn (RentalInvoice $invoice): int => $order[$invoice->id] ?? PHP_INT_MAX)
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
        $this->authorizeExport('rental.invoice');

        return $this->renderer->print($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request)), __('Invoices'));
    }

    private function tab(Request $request): string
    {
        return (string) $request->query('tab', 'all');
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

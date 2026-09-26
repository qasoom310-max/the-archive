<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Services\RentalQuotationPdf;
use Modules\Rental\Services\RentalQuotationRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the quotations list: CSV, Excel, PDF and a printable view —
 * all four from the SAME filtered rows as {@see RentalQuotationRows}.
 *
 * "PDF" is the exception: when rows are ticked, it does not export a data
 * table of them — it downloads the actual quotation document(s) those rows
 * represent (one page each, {@see RentalQuotationPdf}), same as the download
 * icon on a single row. Untick everything and it goes back to the plain
 * tabular report of the whole tab, exactly like CSV/Excel/Print still do.
 */
final class RentalQuotationExportController
{
    use GuardsExport;

    public function __construct(
        private readonly RentalQuotationRows $rows,
        private readonly TabularRenderer $renderer,
        private readonly RentalQuotationPdf $quotationPdf,
    ) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('rental.quotation');

        return $this->renderer->csv($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request), $this->search($request)), $this->exportFilename('rental-quotations'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('rental.quotation');

        return $this->renderer->excel($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request), $this->search($request)), $this->exportFilename('rental-quotations'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('rental.quotation');

        $ids = $this->ids($request);
        if ($ids !== []) {
            return $this->quotationDocuments($ids);
        }

        return $this->renderer->pdf($this->rows->headings(), $this->rows->all($this->tab($request), $ids, $this->search($request)), __('Quotations'), $this->exportFilename('rental-quotations'));
    }

    /**
     * The ticked rows' own quotation documents, one page each, in ticked order.
     *
     * @param  list<int>  $ids
     */
    private function quotationDocuments(array $ids): Response
    {
        $order = array_flip($ids);

        $quotations = RentalQuotation::query()
            ->with(['customer', 'vehicle'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(static fn (RentalQuotation $quote): int => $order[$quote->id] ?? PHP_INT_MAX)
            ->values();

        $first = $quotations->first();
        if ($first === null) {
            abort(404);
        }

        if ($quotations->count() === 1) {
            return response($this->quotationPdf->render($first), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $this->quotationPdf->filename($first) . '"',
            ]);
        }

        return response($this->quotationPdf->renderMany($quotations), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->quotationPdf->filenameForMany($quotations->count()) . '"',
        ]);
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('rental.quotation');

        return $this->renderer->print($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request), $this->search($request)), __('Quotations'));
    }

    private function search(Request $request): string
    {
        return (string) $request->query('q', '');
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

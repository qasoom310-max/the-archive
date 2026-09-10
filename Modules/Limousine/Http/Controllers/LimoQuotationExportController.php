<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\LimoQuotationRows;
use Modules\Limousine\Services\QuotationPdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the quotations list: CSV, Excel, PDF and a printable view —
 * all four from the SAME filtered rows as {@see LimoQuotationRows}.
 *
 * "PDF" is the exception: when rows are ticked, it does not export a data
 * table of them — it downloads the actual quotation document(s) those rows
 * represent (one page each, {@see QuotationPdf}), same as the download icon
 * on a single row. Untick everything and it goes back to the plain tabular
 * report of the whole tab, exactly like CSV/Excel/Print still do.
 */
final class LimoQuotationExportController
{
    use GuardsExport;

    public function __construct(
        private readonly LimoQuotationRows $rows,
        private readonly TabularRenderer $renderer,
        private readonly QuotationPdf $quotationPdf,
    ) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.quotation');

        return $this->renderer->csv($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request)), $this->exportFilename('limousine-quotations'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.quotation');

        return $this->renderer->excel($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request)), $this->exportFilename('limousine-quotations'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('limousine.quotation');

        $ids = $this->ids($request);
        if ($ids !== []) {
            return $this->quotationDocuments($ids);
        }

        return $this->renderer->pdf($this->rows->headings(), $this->rows->all($this->tab($request), $ids), __('Quotations'), $this->exportFilename('limousine-quotations'));
    }

    /**
     * The ticked rows' own quotation documents, one page each, in ticked order.
     *
     * @param  list<int>  $ids
     */
    private function quotationDocuments(array $ids): Response
    {
        $order = array_flip($ids);

        $quotes = LimoQuotation::query()
            ->with(['customer', 'legs'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(static fn (LimoQuotation $quote): int => $order[$quote->id] ?? PHP_INT_MAX)
            ->values();

        $first = $quotes->first();
        if ($first === null) {
            abort(404);
        }

        if ($quotes->count() === 1) {
            return response($this->quotationPdf->render($first), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $this->quotationPdf->filename($first) . '"',
            ]);
        }

        return response($this->quotationPdf->renderMany($quotes), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->quotationPdf->filenameForMany($quotes->count()) . '"',
        ]);
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('limousine.quotation');

        return $this->renderer->print($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request)), __('Quotations'));
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

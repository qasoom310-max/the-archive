<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Limousine\Services\LimoQuotationRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the quotations list: CSV, Excel, PDF and a printable view —
 * all four from the SAME filtered rows as {@see LimoQuotationRows}.
 */
final class LimoQuotationExportController
{
    use GuardsExport;

    public function __construct(private readonly LimoQuotationRows $rows, private readonly TabularRenderer $renderer) {}

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

        return $this->renderer->pdf($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request)), __('Quotations'), $this->exportFilename('limousine-quotations'));
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

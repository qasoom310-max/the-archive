<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Rental\Services\RentalInvoiceRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the invoices list: CSV, Excel, PDF and a printable view — all
 * four from the SAME filtered rows as {@see RentalInvoiceRows}, so a download
 * is always of the tab the office was actually looking at.
 */
final class RentalInvoiceExportController
{
    use GuardsExport;

    public function __construct(private readonly RentalInvoiceRows $rows, private readonly TabularRenderer $renderer) {}

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

        return $this->renderer->pdf($this->rows->headings(), $this->rows->all($this->tab($request), $this->ids($request)), __('Invoices'), $this->exportFilename('rental-invoices'));
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

<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Rental\Services\RentalQuotationRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the quotations list: CSV, Excel, PDF and a printable view —
 * all four from the SAME filtered rows as {@see RentalQuotationRows}.
 */
final class RentalQuotationExportController
{
    use GuardsExport;

    public function __construct(private readonly RentalQuotationRows $rows, private readonly TabularRenderer $renderer) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('rental.quotation');

        return $this->renderer->csv($this->rows->headings(), $this->rows->all($this->tab($request)), $this->exportFilename('rental-quotations'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('rental.quotation');

        return $this->renderer->excel($this->rows->headings(), $this->rows->all($this->tab($request)), $this->exportFilename('rental-quotations'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('rental.quotation');

        return $this->renderer->pdf($this->rows->headings(), $this->rows->all($this->tab($request)), __('Quotations'), $this->exportFilename('rental-quotations'));
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('rental.quotation');

        return $this->renderer->print($this->rows->headings(), $this->rows->all($this->tab($request)), __('Quotations'));
    }

    private function tab(Request $request): string
    {
        return (string) $request->query('tab', 'all');
    }
}

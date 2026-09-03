<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Rental\Services\RentalReceiptRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the receipts list: CSV, Excel, PDF and a printable view — all
 * four from the SAME filtered rows as {@see RentalReceiptRows}.
 */
final class RentalReceiptExportController
{
    use GuardsExport;

    public function __construct(private readonly RentalReceiptRows $rows, private readonly TabularRenderer $renderer) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('rental.receipt');

        return $this->renderer->csv($this->rows->headings(), $this->rows->all($this->search($request)), $this->exportFilename('rental-receipts'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('rental.receipt');

        return $this->renderer->excel($this->rows->headings(), $this->rows->all($this->search($request)), $this->exportFilename('rental-receipts'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('rental.receipt');

        return $this->renderer->pdf($this->rows->headings(), $this->rows->all($this->search($request)), __('Receipts'), $this->exportFilename('rental-receipts'));
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('rental.receipt');

        return $this->renderer->print($this->rows->headings(), $this->rows->all($this->search($request)), __('Receipts'));
    }

    private function search(Request $request): string
    {
        return (string) $request->query('q', '');
    }
}

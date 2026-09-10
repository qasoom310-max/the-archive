<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Rental\Services\RentalOrderRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the orders list: CSV, Excel, PDF and a printable view — all
 * four from the SAME filtered rows as {@see RentalOrderRows} (tab, pick-up
 * date range, and search all ride along).
 */
final class RentalOrderExportController
{
    use GuardsExport;

    public function __construct(private readonly RentalOrderRows $rows, private readonly TabularRenderer $renderer) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('rental.order');

        return $this->renderer->csv($this->rows->headings(), $this->rowsFor($request), $this->exportFilename('rental-orders'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('rental.order');

        return $this->renderer->excel($this->rows->headings(), $this->rowsFor($request), $this->exportFilename('rental-orders'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('rental.order');

        return $this->renderer->pdf($this->rows->headings(), $this->rowsFor($request), __('Orders'), $this->exportFilename('rental-orders'));
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('rental.order');

        return $this->renderer->print($this->rows->headings(), $this->rowsFor($request), __('Orders'));
    }

    /**
     * @return list<array<string, string>>
     */
    private function rowsFor(Request $request): array
    {
        return $this->rows->all(
            (string) $request->query('tab', 'all'),
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
            (string) $request->query('q', ''),
        );
    }
}

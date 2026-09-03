<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Limousine\Services\LimoInvoiceRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the invoices list: CSV, Excel, PDF and a printable view — all
 * four from the SAME filtered rows as {@see LimoInvoiceRows} (tab, issue-date
 * window and search all ride along).
 */
final class LimoInvoiceExportController
{
    use GuardsExport;

    public function __construct(private readonly LimoInvoiceRows $rows, private readonly TabularRenderer $renderer) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.invoice');

        return $this->renderer->csv($this->rows->headings(), $this->rowsFor($request), $this->exportFilename('limousine-invoices'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.invoice');

        return $this->renderer->excel($this->rows->headings(), $this->rowsFor($request), $this->exportFilename('limousine-invoices'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('limousine.invoice');

        return $this->renderer->pdf($this->rows->headings(), $this->rowsFor($request), __('Invoices'), $this->exportFilename('limousine-invoices'));
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('limousine.invoice');

        return $this->renderer->print($this->rows->headings(), $this->rowsFor($request), __('Invoices'));
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

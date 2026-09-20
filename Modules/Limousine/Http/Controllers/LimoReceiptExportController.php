<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Limousine\Services\LimoReceiptRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the receipts list: CSV, Excel, PDF and a printable view — all
 * four from the SAME filtered rows as {@see LimoReceiptRows} (tab, method,
 * search, date range and who created it all ride along).
 */
final class LimoReceiptExportController
{
    use GuardsExport;

    public function __construct(private readonly LimoReceiptRows $rows, private readonly TabularRenderer $renderer) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.receipt');

        return $this->renderer->csv($this->rows->headings(), $this->rowsFor($request), $this->exportFilename('limousine-receipts'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.receipt');

        return $this->renderer->excel($this->rows->headings(), $this->rowsFor($request), $this->exportFilename('limousine-receipts'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('limousine.receipt');

        return $this->renderer->pdf($this->rows->headings(), $this->rowsFor($request), __('Receipts'), $this->exportFilename('limousine-receipts'));
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('limousine.receipt');

        return $this->renderer->print($this->rows->headings(), $this->rowsFor($request), __('Receipts'));
    }

    /**
     * @return list<array<string, string>>
     */
    private function rowsFor(Request $request): array
    {
        return $this->rows->all(
            (string) $request->query('tab', ''),
            (string) $request->query('method', ''),
            (string) $request->query('q', ''),
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
            (string) $request->query('prepared_by', ''),
        );
    }
}

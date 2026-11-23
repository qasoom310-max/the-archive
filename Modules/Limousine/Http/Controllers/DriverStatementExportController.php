<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Limousine\Support\DriverStatementRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of one driver's cash statement (the Driver statement tab on Petty
 * Cash): Excel, PDF, print and CSV, for the audit.
 */
final class DriverStatementExportController
{
    use GuardsExport;

    public function __construct(private readonly DriverStatementRows $rows, private readonly TabularRenderer $renderer) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.petty_cash');

        return $this->renderer->csv($this->rows->headings(), $this->rows->rows($this->driver($request), $this->month($request)), $this->exportFilename('driver-cash-statement'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.petty_cash');

        return $this->renderer->excel($this->rows->headings(), $this->rows->rows($this->driver($request), $this->month($request)), $this->exportFilename('driver-cash-statement'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('limousine.petty_cash');

        return $this->renderer->pdf($this->rows->headings(), $this->rows->rows($this->driver($request), $this->month($request)), $this->rows->title($this->driver($request), $this->month($request)), $this->exportFilename('driver-cash-statement'));
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('limousine.petty_cash');

        return $this->renderer->print($this->rows->headings(), $this->rows->rows($this->driver($request), $this->month($request)), $this->rows->title($this->driver($request), $this->month($request)));
    }

    private function driver(Request $request): int
    {
        $id = $request->query('driver');
        abort_unless(is_string($id) && ctype_digit($id), 404);

        return (int) $id;
    }

    private function month(Request $request): string
    {
        return (string) $request->query('month', 'all');
    }
}

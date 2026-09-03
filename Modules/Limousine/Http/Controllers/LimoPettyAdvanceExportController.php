<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Limousine\Services\LimoPettyAdvanceRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the petty-cash advances list: CSV, Excel, PDF and a printable
 * view — all four from the SAME filtered rows as {@see LimoPettyAdvanceRows}
 * (the open/cleared tab). The Report tab's by-category / by-driver breakdown
 * is a different shape and is not what these serve.
 */
final class LimoPettyAdvanceExportController
{
    use GuardsExport;

    public function __construct(private readonly LimoPettyAdvanceRows $rows, private readonly TabularRenderer $renderer) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.petty_cash');

        return $this->renderer->csv($this->rows->headings(), $this->rows->all($this->tab($request)), $this->exportFilename('limousine-petty-cash'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.petty_cash');

        return $this->renderer->excel($this->rows->headings(), $this->rows->all($this->tab($request)), $this->exportFilename('limousine-petty-cash'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('limousine.petty_cash');

        return $this->renderer->pdf($this->rows->headings(), $this->rows->all($this->tab($request)), __('Petty Cash'), $this->exportFilename('limousine-petty-cash'));
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('limousine.petty_cash');

        return $this->renderer->print($this->rows->headings(), $this->rows->all($this->tab($request)), __('Petty Cash'));
    }

    private function tab(Request $request): string
    {
        return (string) $request->query('tab', 'open');
    }
}

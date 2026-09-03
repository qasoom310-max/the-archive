<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Export\ListExportRows;
use App\Erp\Export\TabularRenderer;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of any engine list export: CSV, Excel, PDF and a printable view.
 *
 * One controller for every model that has an `ir_ui_view` list — the same
 * shape the Limousine booking queue proved out first ({@see
 * \Modules\Limousine\Http\Controllers\LimoQueueExportController}), generalised
 * behind {@see ListExportRows} instead of one bespoke controller per model.
 * Every format renders rows built from the SAME filtered/sorted query, so an
 * export is always of what the screen it came from was showing — never the
 * whole table regardless of what was on screen. The actual CSV/Excel/PDF/Print
 * mechanics live in {@see TabularRenderer}, shared with every bespoke (non-
 * engine-list) screen's own export controller.
 *
 * Read-gated exactly like the screen: an export is a copy of the data, so it
 * must never be a way around that screen's own permission.
 */
final class ListExportController
{
    public function __construct(private readonly TabularRenderer $renderer) {}

    public function csv(Request $request, string $modelKey): StreamedResponse
    {
        $rows = $this->authorized($modelKey);

        return $this->renderer->csv($rows->headings(), $rows->rows($request), $this->filename($modelKey));
    }

    public function excel(Request $request, string $modelKey): StreamedResponse
    {
        $rows = $this->authorized($modelKey);

        return $this->renderer->excel($rows->headings(), $rows->rows($request), $this->filename($modelKey));
    }

    public function pdf(Request $request, string $modelKey): Response
    {
        $rows = $this->authorized($modelKey);

        return $this->renderer->pdf($rows->headings(), $rows->rows($request), $this->title($request), $this->filename($modelKey));
    }

    public function print(Request $request, string $modelKey): View
    {
        $rows = $this->authorized($modelKey);

        return $this->renderer->print($rows->headings(), $rows->rows($request), $this->title($request));
    }

    private function authorized(string $modelKey): ListExportRows
    {
        app(AccessControl::class)->authorize(Auth::user(), $modelKey, Permission::Read);

        return new ListExportRows($modelKey);
    }

    private function title(Request $request): string
    {
        $title = trim((string) $request->query('title', ''));

        return $title !== '' ? $title : __('Records');
    }

    private function filename(string $modelKey): string
    {
        return str_replace('.', '-', $modelKey) . '-' . now()->format('Y-m-d');
    }
}

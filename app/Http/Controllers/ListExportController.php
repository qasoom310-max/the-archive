<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Export\ListExportRows;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
 * whole table regardless of what was on screen.
 *
 * Read-gated exactly like the screen: an export is a copy of the data, so it
 * must never be a way around that screen's own permission.
 */
final class ListExportController
{
    public function csv(Request $request, string $modelKey): StreamedResponse
    {
        $rows = $this->authorized($modelKey);
        $headings = $rows->headings();
        $data = $rows->rows($request);

        return response()->streamDownload(function () use ($data, $headings): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // Excel opens UTF-8 CSV as mojibake without a BOM — matters here
            // since names and addresses are often Arabic.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($headings));
            foreach ($data as $row) {
                fputcsv($out, $this->line($row, $headings));
            }
            fclose($out);
        }, $this->filename($modelKey, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function excel(Request $request, string $modelKey): StreamedResponse
    {
        $rows = $this->authorized($modelKey);
        $headings = $rows->headings();
        $data = $rows->rows($request);

        return response()->streamDownload(function () use ($data, $headings): void {
            $book = new Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->fromArray(array_values($headings), null, 'A1');

            $line = 2;
            foreach ($data as $row) {
                $sheet->fromArray($this->line($row, $headings), null, 'A' . $line);
                $line++;
            }

            $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
            foreach (range('A', $sheet->getHighestColumn()) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            (new Xlsx($book))->save('php://output');
            // Spreadsheets hold their sheets in memory until released.
            $book->disconnectWorksheets();
        }, $this->filename($modelKey, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function pdf(Request $request, string $modelKey): Response
    {
        $rows = $this->authorized($modelKey);

        // Landscape whenever there are enough columns that portrait would
        // squeeze them past reading size — the same threshold the queue
        // export picked for its own 17 columns.
        $paper = count($rows->headings()) > 6 ? 'landscape' : 'portrait';

        return Pdf::loadView('exports.list-print', [
            'rows' => $rows->rows($request),
            'headings' => $rows->headings(),
            'title' => $this->title($request),
            'forPdf' => true,
        ])->setPaper('a4', $paper)->download($this->filename($modelKey, 'pdf'));
    }

    public function print(Request $request, string $modelKey): View
    {
        $rows = $this->authorized($modelKey);

        return view('exports.list-print', [
            'rows' => $rows->rows($request),
            'headings' => $rows->headings(),
            'title' => $this->title($request),
            'forPdf' => false,
        ]);
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

    /**
     * One row in heading order, so every format lines up with its header.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string>  $headings
     * @return list<string>
     */
    private function line(array $row, array $headings): array
    {
        $out = [];
        foreach (array_keys($headings) as $key) {
            $out[] = $row[$key] ?? '';
        }

        return $out;
    }

    private function filename(string $modelKey, string $extension): string
    {
        return str_replace('.', '-', $modelKey) . '-' . now()->format('Y-m-d') . '.' . $extension;
    }
}

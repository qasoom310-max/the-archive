<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Services\LimoQueueRows;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the bookings queue: CSV, Excel, PDF and a printable view.
 *
 * Every format renders the SAME rows as the screen, via {@see LimoQueueRows} —
 * the filters ride along as query parameters, so an export is always of what
 * the user is actually looking at rather than the whole table. (Copy needs no
 * endpoint: it lifts the rendered table client-side.)
 *
 * Read-gated like the list it mirrors: an export is a copy of the data, so it
 * must not be a way around the permission on the screen.
 */
final class LimoQueueExportController
{
    public function __construct(private readonly LimoQueueRows $rows) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorize();
        [$rows, $headings] = $this->data($request);

        return response()->streamDownload(function () use ($rows, $headings): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // Excel opens UTF-8 CSV as mojibake without a BOM, which matters
            // here because customer names and locations are often Arabic.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($headings));
            foreach ($rows as $row) {
                fputcsv($out, $this->line($row, $headings));
            }
            fclose($out);
        }, $this->filename('csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorize();
        [$rows, $headings] = $this->data($request);

        return response()->streamDownload(function () use ($rows, $headings): void {
            $book = new Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->fromArray(array_values($headings), null, 'A1');

            $line = 2;
            foreach ($rows as $row) {
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
        }, $this->filename('xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function pdf(Request $request): Response
    {
        $this->authorize();
        [$rows, $headings] = $this->data($request);

        // Landscape: sixteen columns will not fit across a portrait page.
        return Pdf::loadView('limousine::queue-print', [
            'rows' => $rows,
            'headings' => $headings,
            'forPdf' => true,
        ])->setPaper('a4', 'landscape')->download($this->filename('pdf'));
    }

    public function print(Request $request): View
    {
        $this->authorize();
        [$rows, $headings] = $this->data($request);

        return view('limousine::queue-print', [
            'rows' => $rows,
            'headings' => $headings,
            'forPdf' => false,
        ]);
    }

    private function authorize(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.booking', Permission::Read);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function data(Request $request): array
    {
        // "Live entry data" is a backup of everything the historical import
        // never touched, not a filter on top of whatever tab/date/search is
        // currently on screen — so it deliberately ignores all of those and
        // asks for the complete live set. A tab of '' matches none of the
        // status tabs AND isn't TAB_ALL, so it skips even TAB_ALL's own
        // "hide cancelled" rule — a backup must not quietly drop cancelled
        // trips that were genuinely entered live.
        if ($request->boolean('live')) {
            return [$this->rows->all('', '', '', '', '', 'desc', true), $this->rows->headings()];
        }

        $tab = (string) $request->query('tab', 'all');
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        // Search rides along too: an export is of what the user is looking at.
        $search = (string) $request->query('search', '');
        // …and in the order they sorted it into. A sheet that reorders itself on
        // the way to the printer is the drift this class exists to prevent.
        $sort = (string) $request->query('sort', '');
        $dir = (string) $request->query('dir', 'desc');

        return [$this->rows->all($tab, $from, $to, $search, $sort, $dir), $this->rows->headings()];
    }

    /**
     * One row in heading order, so every format lines up with its header.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $headings
     * @return list<string>
     */
    private function line(array $row, array $headings): array
    {
        $out = [];
        foreach (array_keys($headings) as $key) {
            $out[] = (string) ($row[$key] ?? '');
        }

        return $out;
    }

    private function filename(string $extension): string
    {
        return 'limo-queue-' . now()->format('Y-m-d') . '.' . $extension;
    }
}

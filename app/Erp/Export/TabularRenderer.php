<?php

declare(strict_types=1);

namespace App\Erp\Export;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one CSV/Excel/PDF/Print engine behind every export in the app.
 *
 * Extracted out of {@see \App\Http\Controllers\ListExportController} so a
 * bespoke hand-written list (the booking queue's shape, generalised) gets the
 * same four formats without re-writing the fputcsv/PhpSpreadsheet/DomPDF
 * plumbing per screen — only WHAT the rows are differs screen to screen, never
 * how they are turned into a file. Every caller hands over rows already built
 * (headings + formatted string cells), so this class knows nothing about any
 * particular model.
 */
final class TabularRenderer
{
    /**
     * @param  array<string, string>  $headings  field => label, in column order
     * @param  iterable<array<string, string>>  $rows
     */
    public function csv(array $headings, iterable $rows, string $filenameBase): StreamedResponse
    {
        return \response()->streamDownload(function () use ($rows, $headings): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // Excel opens UTF-8 CSV as mojibake without a BOM — matters here
            // since names and addresses are often Arabic.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($headings));
            foreach ($rows as $row) {
                fputcsv($out, $this->line($row, $headings));
            }
            fclose($out);
        }, $filenameBase . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, string>  $headings
     * @param  iterable<array<string, string>>  $rows
     */
    public function excel(array $headings, iterable $rows, string $filenameBase): StreamedResponse
    {
        return \response()->streamDownload(function () use ($rows, $headings): void {
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
        }, $filenameBase . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<string, string>  $headings
     * @param  list<array<string, string>>  $rows
     */
    public function pdf(array $headings, array $rows, string $title, string $filenameBase): Response
    {
        // Landscape whenever there are enough columns that portrait would
        // squeeze them past reading size.
        $paper = count($headings) > 6 ? 'landscape' : 'portrait';

        return Pdf::loadView('exports.list-print', [
            'rows' => $rows,
            'headings' => $headings,
            'title' => $title,
            'forPdf' => true,
        ])->setPaper('a4', $paper)->download($filenameBase . '.pdf');
    }

    /**
     * @param  array<string, string>  $headings
     * @param  list<array<string, string>>  $rows
     */
    public function print(array $headings, array $rows, string $title): View
    {
        return \view('exports.list-print', [
            'rows' => $rows,
            'headings' => $headings,
            'title' => $title,
            'forPdf' => false,
        ]);
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
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Export\TabularRenderer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Exported text that starts with "=" stays text. The spreadsheet library takes
 * such text for a formula: a note like "== VIP ==" made the whole Excel
 * download fail, and a crafted one would run as a live formula when opened.
 */
final class SpreadsheetTextCellsTest extends TestCase
{
    public function test_text_starting_with_equals_is_written_as_text_and_saves(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();

        TabularRenderer::writeRow($sheet, ['== VIP ==', '=HYPERLINK("http://example.test","x")', 'Seef', '12.500'], 1);

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
        $this->assertSame('== VIP ==', $sheet->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B1')->getDataType());
        $this->assertSame('Seef', $sheet->getCell('C1')->getValue());

        $path = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        try {
            (new Xlsx($book))->save($path);
            $this->assertGreaterThan(0, (int) filesize($path));
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }
    }
}

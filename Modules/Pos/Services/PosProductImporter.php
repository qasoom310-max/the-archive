<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Modules\Pos\Imports\ImportReport;
use Modules\Pos\Imports\ImportRow;
use Modules\Pos\Models\PosProduct;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Parses .xlsx/.csv product files into a typed ImportReport (preview),
 * then applies the report's valid rows to `pos_products` (upsert by
 * barcode). Invalid rows are skipped and reported back — partial success
 * by design, so a single bad cell never kills the whole batch.
 *
 * Header matching is case-insensitive and accepts several synonyms
 * ("Sale Price", "Price", "sale_price" all map to the same column).
 */
final class PosProductImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'name' => 'name',
        'barcode' => 'barcode',
        'sale price' => 'sale_price',
        'sale_price' => 'sale_price',
        'price' => 'sale_price',
        'cost price' => 'cost_price',
        'cost_price' => 'cost_price',
        'cost' => 'cost_price',
        'tax %' => 'tax_rate',
        'tax' => 'tax_rate',
        'tax_rate' => 'tax_rate',
    ];

    public function parse(string $absolutePath): ImportReport
    {
        $report = new ImportReport();

        try {
            $spreadsheet = IOFactory::load($absolutePath);
        } catch (Throwable $e) {
            // Catches PhpSpreadsheet\Reader\Exception (which extends \Exception)
            // plus any other I/O failure (file vanished, locked, etc.).
            $report->fileErrors[] = 'Could not read the file: ' . $e->getMessage();

            return $report;
        }

        /** @var array<int, array<int, mixed>> $matrix */
        $matrix = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        if ($matrix === []) {
            $report->fileErrors[] = 'The file is empty.';

            return $report;
        }

        $headerRow = array_shift($matrix);
        $headerIndex = $this->resolveHeaders($headerRow);

        if (! isset($headerIndex['name'])) {
            $report->fileErrors[] = "Missing required column: 'Name'.";
        }
        if (! isset($headerIndex['sale_price'])) {
            $report->fileErrors[] = "Missing required column: 'Sale Price'.";
        }
        if ($report->fileErrors !== []) {
            return $report;
        }

        $existingByBarcode = $this->fetchExistingByBarcode($matrix, $headerIndex);

        /** @var array<string, int> $barcodesSeen barcode → first rowNumber it appeared */
        $barcodesSeen = [];

        foreach ($matrix as $i => $row) {
            $rowNumber = $i + 2; // 1 (header) + 1 (1-based)

            $name = trim((string) ($row[$headerIndex['name']] ?? ''));
            $barcode = isset($headerIndex['barcode'])
                ? trim((string) ($row[$headerIndex['barcode']] ?? ''))
                : '';
            $salePriceRaw = $row[$headerIndex['sale_price']] ?? null;
            $costPriceRaw = isset($headerIndex['cost_price']) ? ($row[$headerIndex['cost_price']] ?? null) : null;
            $taxRaw = isset($headerIndex['tax_rate']) ? ($row[$headerIndex['tax_rate']] ?? null) : null;

            // Quietly skip wholly-blank trailing rows (Excel ranges often have these).
            if ($name === '' && $barcode === '' && $salePriceRaw === null && $costPriceRaw === null && $taxRaw === null) {
                continue;
            }

            $errors = [];

            if ($name === '') {
                $errors[] = 'Name is required.';
            }

            $salePrice = $this->toFloat($salePriceRaw);
            if ($salePrice === null) {
                $errors[] = 'Sale Price is required and must be a number.';
            } elseif ($salePrice < 0) {
                $errors[] = 'Sale Price cannot be negative.';
            }

            $costPrice = $this->toFloat($costPriceRaw);
            if ($costPrice !== null && $costPrice < 0) {
                $errors[] = 'Cost Price cannot be negative.';
            }

            $taxRate = $this->toFloat($taxRaw);
            if ($taxRate !== null && ($taxRate < 0 || $taxRate > 100)) {
                $errors[] = 'Tax % must be between 0 and 100.';
            }

            if ($barcode !== '') {
                if (isset($barcodesSeen[$barcode])) {
                    $errors[] = 'Duplicate barcode within file (first seen on row ' . $barcodesSeen[$barcode] . ').';
                } else {
                    $barcodesSeen[$barcode] = $rowNumber;
                }
            }

            $existing = $barcode !== '' ? ($existingByBarcode[$barcode] ?? null) : null;
            $action = $errors !== []
                ? 'skip'
                : ($existing !== null ? 'update' : 'create');

            $report->rows[] = new ImportRow(
                rowNumber: $rowNumber,
                name: $name,
                barcode: $barcode !== '' ? $barcode : null,
                salePrice: $salePrice,
                costPrice: $costPrice,
                taxRate: $taxRate,
                action: $action,
                errors: $errors,
                resolvedId: $existing?->id,
            );

            if ($action === 'skip') {
                $report->skippedCount++;
            }
        }

        return $report;
    }

    public function apply(ImportReport $report): ImportReport
    {
        // No transaction wrapper — partial success is the contract. A bad row
        // shouldn't roll back good ones. Each row is its own unit of work.
        foreach ($report->rows as $row) {
            if ($row->action === 'create') {
                PosProduct::query()->create([
                    'name' => $row->name,
                    'barcode' => $row->barcode,
                    'price' => $row->salePrice ?? 0.0,
                    'cost_price' => $row->costPrice ?? 0.0,
                    'tax_rate' => $row->taxRate ?? 0.0,
                ]);
                $report->createdCount++;

                continue;
            }

            if ($row->action === 'update' && $row->resolvedId !== null) {
                $product = PosProduct::query()->find($row->resolvedId);

                if ($product === null) {
                    // Row was matched at parse time but the product has since
                    // been deleted. Count as skipped rather than blow up.
                    $report->skippedCount++;

                    continue;
                }

                $product->fill([
                    'name' => $row->name,
                    // Preserve previous value for blank-but-optional inputs so
                    // partial updates don't wipe fields the user left empty.
                    'price' => $row->salePrice ?? $product->price,
                    'cost_price' => $row->costPrice ?? $product->cost_price,
                    'tax_rate' => $row->taxRate ?? $product->tax_rate,
                ])->save();
                $report->updatedCount++;
            }
        }

        return $report;
    }

    /**
     * Pre-fetch existing products whose barcodes appear anywhere in the file,
     * so we don't N+1 the validation pass.
     *
     * @param  array<int, array<int, mixed>>  $matrix
     * @param  array<string, int>             $headerIndex
     * @return array<string, PosProduct>
     */
    private function fetchExistingByBarcode(array $matrix, array $headerIndex): array
    {
        if (! isset($headerIndex['barcode'])) {
            return [];
        }

        $barcodes = [];
        foreach ($matrix as $row) {
            $b = trim((string) ($row[$headerIndex['barcode']] ?? ''));
            if ($b !== '') {
                $barcodes[] = $b;
            }
        }

        if ($barcodes === []) {
            return [];
        }

        /** @var array<string, PosProduct> $byBarcode */
        $byBarcode = [];
        foreach (PosProduct::query()->whereIn('barcode', $barcodes)->get() as $p) {
            if ($p->barcode !== null && $p->barcode !== '') {
                $byBarcode[$p->barcode] = $p;
            }
        }

        return $byBarcode;
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int> canonical key → 0-based column index
     */
    private function resolveHeaders(array $headerRow): array
    {
        $resolved = [];
        $i = 0;
        foreach ($headerRow as $cell) {
            $key = strtolower(trim((string) $cell));
            if ($key !== '' && isset(self::HEADER_MAP[$key])) {
                $resolved[self::HEADER_MAP[$key]] = $i;
            }
            $i++;
        }

        return $resolved;
    }

    private function toFloat(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }

        $s = trim((string) $raw);
        // Tolerate "$10.50", "10,50" (EU decimal comma), bare digits, etc.
        $cleaned = preg_replace('/[^\d.\-,]/u', '', $s) ?? '';
        $cleaned = str_replace(',', '.', $cleaned);

        if ($cleaned === '' || ! is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }
}

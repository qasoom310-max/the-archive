<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Modules\Pos\Imports\ImportReport;
use Modules\Pos\Imports\ImportRow;
use Modules\Pos\Models\PosCategory;
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
        'category' => 'category',
        'category name' => 'category',
        'category_name' => 'category',
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
            $categoryName = isset($headerIndex['category'])
                ? trim((string) ($row[$headerIndex['category']] ?? ''))
                : '';

            // Quietly skip wholly-blank trailing rows (Excel ranges often have these).
            if ($name === '' && $barcode === '' && $salePriceRaw === null && $costPriceRaw === null && $taxRaw === null && $categoryName === '') {
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
                categoryName: $categoryName !== '' ? $categoryName : null,
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

        // Resolve every category name to a real PosCategory id up-front:
        // - Pre-loads existing names in one query (no N+1)
        // - firstOrCreates missing ones, locale-aware via detectLocale()
        // - Cached by name so repeated rows share the same id
        $categoryIds = $this->resolveCategoryIds($report->rows);

        foreach ($report->rows as $row) {
            $categoryId = $row->categoryName !== null && $row->categoryName !== ''
                ? ($categoryIds[$row->categoryName] ?? null)
                : null;

            if ($row->action === 'create') {
                $product = new PosProduct([
                    'barcode' => $row->barcode,
                    'price' => $row->salePrice ?? 0.0,
                    'cost_price' => $row->costPrice ?? 0.0,
                    'tax_rate' => $row->taxRate ?? 0.0,
                    'pos_category_id' => $categoryId,
                ]);
                $product->setTranslation('name', self::detectLocale($row->name), $row->name);
                $product->save();
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

                // Only overwrite the detected-locale translation — preserves
                // the other locale on the existing product (an English-named
                // import row shouldn't wipe the row's existing Arabic name).
                $product->setTranslation('name', self::detectLocale($row->name), $row->name);

                $fill = [
                    // Preserve previous value for blank-but-optional inputs so
                    // partial updates don't wipe fields the user left empty.
                    'price' => $row->salePrice ?? $product->price,
                    'cost_price' => $row->costPrice ?? $product->cost_price,
                    'tax_rate' => $row->taxRate ?? $product->tax_rate,
                ];
                // Only overwrite the category when the row had one — a
                // blank Category cell on an update means "leave it alone".
                if ($categoryId !== null) {
                    $fill['pos_category_id'] = $categoryId;
                }
                $product->fill($fill)->save();
                $report->updatedCount++;
            }
        }

        return $report;
    }

    /**
     * Map every distinct category name across the report to a PosCategory
     * id, creating missing ones on the fly. Returns an empty map if no row
     * carried a category — so the lookup is cheap on imports that don't
     * use the column.
     *
     * @param list<ImportRow> $rows
     * @return array<string, int>
     */
    private function resolveCategoryIds(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            if ($row->action === 'skip') {
                continue; // skipped rows don't get applied, so we don't need their category
            }
            if ($row->categoryName !== null && $row->categoryName !== '' && ! in_array($row->categoryName, $names, true)) {
                $names[] = $row->categoryName;
            }
        }

        if ($names === []) {
            return [];
        }

        // PosCategory.name is translatable JSON now — `whereIn('name', ...)`
        // can't match the raw envelope. Check each unique CSV name against
        // both locale paths in one batched query, then create the rest
        // under the detected locale.
        /** @var array<string, int> $byName */
        $byName = [];

        $existing = PosCategory::query()
            ->where(function ($q) use ($names): void {
                /** @var \Illuminate\Database\Eloquent\Builder<PosCategory> $q */
                $q->whereIn('name->en', $names)->orWhereIn('name->ar', $names);
            })
            ->get(['id', 'name']);

        // For matched rows, figure out WHICH csv name they correspond to —
        // a category might have only one of {en, ar} populated (a fresh
        // Arabic-only category created during a previous import).
        foreach ($existing as $cat) {
            $translations = $cat->getTranslations('name');
            foreach ($translations as $value) {
                if (in_array($value, $names, true)) {
                    $byName[(string) $value] = (int) $cat->id;
                }
            }
        }

        foreach ($names as $name) {
            if (isset($byName[$name])) {
                continue;
            }
            // Locale-keyed array → Spatie stores under the detected locale
            // (Arabic-script names go to `ar`, everything else to `en`).
            $category = PosCategory::query()->create([
                'name' => [self::detectLocale($name) => $name],
            ]);
            $byName[$name] = (int) $category->id;
        }

        return $byName;
    }

    /**
     * Pick the locale to store a translatable value under based on its
     * script. Any Arabic-script characters (Unicode `\p{Arabic}`) → 'ar';
     * everything else → 'en'. Lets the importer take a single "Name"
     * column and route Arabic-named products to the AR pill, English-
     * named products to the EN pill, in the same file.
     *
     * Mixed strings ("Pepsi باربد") get classified as 'ar' as soon as one
     * Arabic glyph is present — the brand half stays readable on the AR
     * side and the user can fill in a clean English translation later via
     * the form's EN pill. Edge case but acceptable for an import tool.
     */
    private static function detectLocale(string $value): string
    {
        return preg_match('/\p{Arabic}/u', $value) === 1 ? 'ar' : 'en';
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

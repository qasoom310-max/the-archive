<?php

declare(strict_types=1);

namespace Modules\Pos\Imports;

/**
 * One parsed-and-validated row from a product import file.
 *
 * `action` is the importer's verdict:
 *   - 'create' = no existing barcode match, row will be inserted
 *   - 'update' = barcode matches an existing product, row will overwrite
 *   - 'skip'   = at least one validation error; row will not be written
 */
final class ImportRow
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $name,
        public readonly ?string $barcode,
        public readonly ?float $salePrice,
        public readonly ?float $costPrice,
        public readonly ?float $taxRate,
        public readonly string $action,
        public readonly array $errors,
        public readonly ?int $resolvedId,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}

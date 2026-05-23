<?php

declare(strict_types=1);

namespace Modules\Pos\Imports;

use Livewire\Wireable;

/**
 * One parsed-and-validated row from a product import file.
 *
 * `action` is the importer's verdict:
 *   - 'create' = no existing barcode match, row will be inserted
 *   - 'update' = barcode matches an existing product, row will overwrite
 *   - 'skip'   = at least one validation error; row will not be written
 *
 * Implements `Wireable` so the parent `ImportReport` (held as a public
 * Livewire property on `PosProductImport`) can round-trip across the
 * dehydrate/hydrate cycle — without it Livewire bails on the typed
 * custom-class property with "Property type not supported".
 */
final class ImportRow implements Wireable
{
    /**
     * @param list<string> $errors
     *
     * @param ?string $categoryName Free-text name from the file's "Category"
     *                              column. Importer firstOrCreates a PosCategory
     *                              with this exact name at apply-time — null /
     *                              empty leaves the product uncategorised.
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
        public readonly ?string $categoryName = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLivewire(): array
    {
        return [
            'rowNumber' => $this->rowNumber,
            'name' => $this->name,
            'barcode' => $this->barcode,
            'salePrice' => $this->salePrice,
            'costPrice' => $this->costPrice,
            'taxRate' => $this->taxRate,
            'action' => $this->action,
            'errors' => $this->errors,
            'resolvedId' => $this->resolvedId,
            'categoryName' => $this->categoryName,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromLivewire($value): self
    {
        /** @var list<string> $errors */
        $errors = is_array($value['errors'] ?? null) ? array_values(array_filter(
            $value['errors'],
            static fn (mixed $e): bool => is_string($e),
        )) : [];

        return new self(
            rowNumber: (int) ($value['rowNumber'] ?? 0),
            name: (string) ($value['name'] ?? ''),
            barcode: isset($value['barcode']) && is_string($value['barcode']) ? $value['barcode'] : null,
            salePrice: isset($value['salePrice']) && is_numeric($value['salePrice']) ? (float) $value['salePrice'] : null,
            costPrice: isset($value['costPrice']) && is_numeric($value['costPrice']) ? (float) $value['costPrice'] : null,
            taxRate: isset($value['taxRate']) && is_numeric($value['taxRate']) ? (float) $value['taxRate'] : null,
            action: (string) ($value['action'] ?? 'skip'),
            errors: $errors,
            resolvedId: isset($value['resolvedId']) && is_numeric($value['resolvedId']) ? (int) $value['resolvedId'] : null,
            categoryName: isset($value['categoryName']) && is_string($value['categoryName']) && $value['categoryName'] !== ''
                ? $value['categoryName']
                : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Erp\Views;

/**
 * One column of a List view, parsed from the `arch.columns[]` metadata.
 */
final readonly class ColumnDef
{
    /**
     * @param 'left'|'right'|'center'                       $align
     * @param 'text'|'number'|'date'|'datetime'|'badge'|'bool' $format
     */
    public function __construct(
        public string $field,
        public string $label,
        public bool $sortable = true,
        public bool $sum = false,
        public bool $avg = false,
        public string $align = 'left',
        public string $format = 'text',
    ) {}

    public function isAggregated(): bool
    {
        return $this->sum || $this->avg;
    }
}

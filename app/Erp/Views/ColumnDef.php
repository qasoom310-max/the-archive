<?php

declare(strict_types=1);

namespace App\Erp\Views;

/**
 * One column of a List view, parsed from the `arch.columns[]` metadata.
 */
final readonly class ColumnDef
{
    /**
     * @param 'left'|'right'|'center'                                  $align
     * @param 'text'|'number'|'money'|'date'|'datetime'|'badge'|'bool'|'toggle' $format
     *               'toggle' renders an interactive switch (Yes/No is a
     *               drag-distance-zero click in the list itself; gated on
     *               Write permission server-side via ListView::toggleBoolean)
     * @param  ?string  $sortField  Optional SQL column override for sorting:
     *                              used when `field` is an accessor (e.g.
     *                              `processed_by` → name from a relation)
     *                              that has no underlying column to ORDER BY.
     *                              Specify the real column to sort on instead
     *                              (e.g. `user_id`). Defaults to `field`.
     */
    /**
     * @param bool $hiddenByDefault  When true, the column is hidden on a
     *                               user's first visit. They can still
     *                               opt-in via the engine's column picker
     *                               (UserViewPreference). Use for fields
     *                               that aren't load-bearing for the
     *                               typical workflow (e.g. internal IDs,
     *                               compliance details, low-traffic stats)
     *                               but still belong in the arch for
     *                               power users who care.
     */
    public function __construct(
        public string $field,
        public string $label,
        public bool $sortable = true,
        public bool $sum = false,
        public bool $avg = false,
        public string $align = 'left',
        public string $format = 'text',
        public ?string $sortField = null,
        public bool $hiddenByDefault = false,
    ) {}

    /**
     * The SQL column to use when ordering by this column — falls back to
     * `field` for the usual case where the displayed field IS a column.
     */
    public function sortColumn(): string
    {
        return $this->sortField ?? $this->field;
    }

    public function isAggregated(): bool
    {
        return $this->sum || $this->avg;
    }
}

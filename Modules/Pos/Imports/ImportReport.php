<?php

declare(strict_types=1);

namespace Modules\Pos\Imports;

/**
 * Result of an import preview (and, post-apply, the commit counters).
 *
 * `fileErrors` carries top-level problems — bad file, missing required
 * columns — that prevented row-level parsing. When `fileErrors` is
 * non-empty, `rows` is empty and nothing can be applied.
 */
final class ImportReport
{
    /**
     * @param list<ImportRow> $rows
     * @param list<string>    $fileErrors
     */
    public function __construct(
        public array $rows = [],
        public array $fileErrors = [],
        public int $createdCount = 0,
        public int $updatedCount = 0,
        public int $skippedCount = 0,
    ) {
    }

    public function isFatal(): bool
    {
        return $this->fileErrors !== [];
    }

    public function validRowCount(): int
    {
        $n = 0;
        foreach ($this->rows as $row) {
            if ($row->isValid()) {
                $n++;
            }
        }

        return $n;
    }
}

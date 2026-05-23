<?php

declare(strict_types=1);

namespace Modules\Pos\Imports;

use Livewire\Wireable;

/**
 * Result of an import preview (and, post-apply, the commit counters).
 *
 * `fileErrors` carries top-level problems — bad file, missing required
 * columns — that prevented row-level parsing. When `fileErrors` is
 * non-empty, `rows` is empty and nothing can be applied.
 *
 * Implements `Wireable` so `PosProductImport` can hold a preview report
 * as a public component property between the user clicking Preview and
 * Confirm. Each row is recursively round-tripped via {@see ImportRow}.
 */
final class ImportReport implements Wireable
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

    /**
     * @return array<string, mixed>
     */
    public function toLivewire(): array
    {
        return [
            'rows' => array_map(static fn (ImportRow $r): array => $r->toLivewire(), $this->rows),
            'fileErrors' => $this->fileErrors,
            'createdCount' => $this->createdCount,
            'updatedCount' => $this->updatedCount,
            'skippedCount' => $this->skippedCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromLivewire($value): self
    {
        /** @var list<ImportRow> $rows */
        $rows = is_array($value['rows'] ?? null)
            ? array_values(array_map(
                /** @param mixed $r */
                static fn ($r): ImportRow => is_array($r)
                    ? ImportRow::fromLivewire($r)
                    : ImportRow::fromLivewire([]),
                $value['rows'],
            ))
            : [];

        /** @var list<string> $fileErrors */
        $fileErrors = is_array($value['fileErrors'] ?? null) ? array_values(array_filter(
            $value['fileErrors'],
            static fn (mixed $e): bool => is_string($e),
        )) : [];

        return new self(
            rows: $rows,
            fileErrors: $fileErrors,
            createdCount: (int) ($value['createdCount'] ?? 0),
            updatedCount: (int) ($value['updatedCount'] ?? 0),
            skippedCount: (int) ($value['skippedCount'] ?? 0),
        );
    }
}

<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\AccountingSequence;

/**
 * Generates the next journal-entry number for a given (prefix, year) pair,
 * formatted as `PREFIX/YYYY/NNNN` (e.g. `MISC/2026/0001`, `SALE/2026/0042`).
 *
 * Race-safe: the `(prefix, year)` row is selected with `lockForUpdate`
 * inside a transaction, so concurrent inserts under MySQL/Postgres each
 * get a distinct number. SQLite is single-writer, so the lock is a no-op
 * but the semantics still hold.
 *
 * The output also satisfies the `journal_entries.number` UNIQUE constraint —
 * which is the actual safety net if two workers ever skipped this service.
 */
final class SequenceGenerator
{
    public function next(string $prefix, ?int $year = null): string
    {
        $year = $year ?? (int) date('Y');
        $prefix = strtoupper(trim($prefix));

        return DB::transaction(function () use ($prefix, $year): string {
            /** @var AccountingSequence $row */
            $row = AccountingSequence::query()
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->lockForUpdate()
                ->first()
                ?? new AccountingSequence(['prefix' => $prefix, 'year' => $year, 'last_number' => 0]);

            $row->last_number = $row->last_number + 1;
            $row->save();

            return sprintf('%s/%d/%04d', $prefix, $year, $row->last_number);
        });
    }
}

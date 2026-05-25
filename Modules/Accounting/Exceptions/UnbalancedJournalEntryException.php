<?php

declare(strict_types=1);

namespace Modules\Accounting\Exceptions;

use RuntimeException;

/**
 * The double-entry invariant — sum(debits) === sum(credits) — was violated
 * at posting time. Thrown by {@see \Modules\Accounting\Services\JournalPoster}
 * (and the `JournalEntry::saving` guard) before any state change touches the
 * DB, so the entry stays in `draft` and the user can fix it.
 *
 * Carrying both totals lets the caller render a precise message
 * ("Out of balance by 0.50") without re-summing in the UI.
 */
final class UnbalancedJournalEntryException extends RuntimeException
{
    public function __construct(
        public readonly float $totalDebit,
        public readonly float $totalCredit,
    ) {
        $diff = round($totalDebit - $totalCredit, 2);
        $direction = $diff > 0 ? 'debit' : 'credit';
        parent::__construct(sprintf(
            'Journal entry is unbalanced: debits %.2f, credits %.2f (%.2f excess %s).',
            $totalDebit,
            $totalCredit,
            abs($diff),
            $direction,
        ));
    }

    public function difference(): float
    {
        return round($this->totalDebit - $this->totalCredit, 2);
    }
}

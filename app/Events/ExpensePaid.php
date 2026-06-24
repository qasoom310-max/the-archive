<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Fired when a recurring expense (Rent, EWA, SIO, LMRA…) is marked paid for a
 * month. The Accounting module — when installed — books Dr Operating Expenses
 * / Cr Bank for the amount, keyed by a deterministic reference so editing the
 * paid amount replaces rather than duplicates the entry.
 */
final class ExpensePaid
{
    public function __construct(
        public readonly int $expenseId,
        public readonly string $name,
        public readonly string $period,   // 'YYYY-MM'
        public readonly float $amount,
        public readonly string $paidOn,   // 'YYYY-MM-DD'
    ) {
    }
}

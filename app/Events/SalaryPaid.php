<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Fired when an employee's salary slip is finalised (or reversed, with
 * `net = 0`). The Accounting module — when installed — books
 * Dr Salaries & Wages / Cr Bank for the net, keyed by a deterministic
 * reference so a re-finalise replaces (rather than duplicates) the entry and
 * a reversal (net 0) just clears it.
 */
final class SalaryPaid
{
    public function __construct(
        public readonly int $employeeId,
        public readonly string $employeeName,
        public readonly string $period,   // 'YYYY-MM'
        public readonly float $net,
        public readonly string $paidOn,   // 'YYYY-MM-DD'
    ) {
    }
}

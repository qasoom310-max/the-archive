<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use App\Events\SalaryPaid;
use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalPoster;
use Throwable;

/**
 * Books a finalised salary slip:
 *
 *     Dr  Salaries & Wages (5020)   net
 *         Cr  Bank (1020)                  net
 *
 * Keyed by a deterministic reference `SAL/{employee}/{period}` so a
 * re-finalise replaces the prior entry (no duplicates) and a reversal
 * (`net = 0`, fired on unmark) simply clears it. Best-effort: a missing
 * account or any error is logged, NOT rethrown — paying salary must never
 * break on an accounting hiccup.
 */
final class RecordSalaryInJournal
{
    public function __construct(private readonly JournalPoster $poster)
    {
    }

    public function handle(SalaryPaid $event): void
    {
        try {
            $this->record($event);
        } catch (Throwable $e) {
            logger()->error('Salary posting failed', ['error' => $e->getMessage()]);
        }
    }

    public function record(SalaryPaid $event): void
    {
        $reference = "SAL/{$event->employeeId}/{$event->period}";
        $this->clear($reference);

        if ($event->net <= 0.0) {
            return; // reversal — cleared above, nothing to post
        }

        $salaries = Account::byCode((string) config('accounting.accounts.salaries'));
        $bank = Account::byCode((string) config('accounting.accounts.bank'));

        if ($salaries === null || $bank === null) {
            return; // chart of accounts not configured — skip silently
        }

        $amount = round($event->net, 2);
        $memo = "Salary {$event->employeeName} {$event->period}";

        $this->poster->record(
            date: Carbon::parse($event->paidOn),
            lines: [
                ['account_id' => (int) $salaries->id, 'debit' => $amount, 'memo' => $memo],
                ['account_id' => (int) $bank->id, 'credit' => $amount, 'memo' => $memo],
            ],
            reference: $reference,
            narration: $memo,
            prefix: (string) config('accounting.sequences.salary', 'SAL'),
        );
    }

    private function clear(string $reference): void
    {
        JournalEntry::query()->where('reference', $reference)->get()->each(function (JournalEntry $entry): void {
            $entry->items()->delete();
            $entry->delete();
        });
    }
}

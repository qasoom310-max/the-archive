<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use App\Events\ExpensePaid;
use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalPoster;
use Throwable;

/**
 * Books a paid recurring expense (Rent, EWA, SIO, LMRA…):
 *
 *     Dr  Operating Expenses (5030)   amount
 *         Cr  Bank (1020)                     amount
 *
 * Keyed by `EXP/{expense}/{period}` so editing the paid amount replaces the
 * prior entry instead of duplicating it. Best-effort — logged, not rethrown.
 */
final class RecordExpenseInJournal
{
    public function __construct(private readonly JournalPoster $poster)
    {
    }

    public function handle(ExpensePaid $event): void
    {
        try {
            $this->record($event);
        } catch (Throwable $e) {
            logger()->error('Expense posting failed', ['error' => $e->getMessage()]);
        }
    }

    public function record(ExpensePaid $event): void
    {
        $reference = "EXP/{$event->expenseId}/{$event->period}";
        $this->clear($reference);

        if ($event->amount <= 0.0) {
            return;
        }

        $expense = Account::byCode((string) config('accounting.accounts.operating_expense'));
        $bank = Account::byCode((string) config('accounting.accounts.bank'));

        if ($expense === null || $bank === null) {
            return;
        }

        $amount = round($event->amount, 2);
        $memo = "{$event->name} {$event->period}";

        $this->poster->record(
            date: Carbon::parse($event->paidOn),
            lines: [
                ['account_id' => (int) $expense->id, 'debit' => $amount, 'memo' => $memo],
                ['account_id' => (int) $bank->id, 'credit' => $amount, 'memo' => $memo],
            ],
            reference: $reference,
            narration: $memo,
            prefix: (string) config('accounting.sequences.expense', 'EXP'),
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

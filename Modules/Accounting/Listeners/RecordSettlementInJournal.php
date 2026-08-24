<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalPoster;
use Throwable;

/**
 * A delivery payout landed: move the money out of "in transit" and into the
 * bank.
 *
 *     Dr  Bank                       received amount
 *     Dr  Operating Expenses         shortfall  (only if they sent LESS)
 *         Cr  Money in Transit               expected amount
 *
 * (An overpayment flips: the extra is credited back to operating expenses so
 * the entry still balances.)
 *
 * The expected amount is what the transit account is actually carrying for this
 * batch — the collected total minus the fees the company already kept, each of
 * which was credited out of transit when the order was paid. So crediting the
 * expected amount clears the batch to zero, and any genuine shortfall lands in
 * expenses instead of quietly distorting the bank balance.
 *
 * Keyed on the settlement reference so a re-fire replaces rather than
 * duplicates. Defensive: a database without these accounts is left alone.
 */
final class RecordSettlementInJournal
{
    public function __construct(private JournalPoster $poster)
    {
    }

    public function handle(object $event): void
    {
        if (! property_exists($event, 'settlement')) {
            return;
        }

        $settlement = $event->settlement;

        try {
            $expected = round((float) $settlement->expected_amount, 2);
            $received = round((float) $settlement->received_amount, 2);

            if ($expected <= 0.0 && $received <= 0.0) {
                return;
            }

            $reference = "STL/{$settlement->reference}";

            $bank = Account::byCode((string) config('accounting.accounts.bank'));
            $transitCode = (string) config('accounting.accounts.money_in_transit', '');
            $transit = $transitCode !== '' ? Account::byCode($transitCode) : null;
            $expense = Account::byCode((string) config('accounting.accounts.operating_expense'));

            if ($bank === null || $transit === null || $expense === null) {
                return;
            }

            $memo = "Delivery payout {$settlement->reference}";

            $lines = [
                ['account_id' => (int) $bank->id, 'debit' => $received, 'memo' => $memo],
                ['account_id' => (int) $transit->id, 'credit' => $expected, 'memo' => $memo],
            ];

            // Gap between what we were owed and what arrived. Short → a cost;
            // over → a credit back. Either way the entry balances.
            $gap = round($expected - $received, 2);
            if (abs($gap) >= 0.01) {
                $lines[] = $gap > 0
                    ? ['account_id' => (int) $expense->id, 'debit' => $gap, 'memo' => "Shortfall on {$settlement->reference}"]
                    : ['account_id' => (int) $expense->id, 'credit' => abs($gap), 'memo' => "Overpayment on {$settlement->reference}"];
            }

            // Replacing the previous entry deletes a POSTED one, so the delete
            // and the rewrite have to be a single operation. Outside a
            // transaction a rewrite that stopped part-way left the books
            // permanently short an entry, and the Trial Balance silently moved.
            DB::transaction(function () use ($settlement, $lines, $reference, $memo): void {
                $this->clear($reference);

                $this->poster->record(
                    date: Carbon::parse($settlement->received_at ?? Carbon::now()),
                    lines: $lines,
                    reference: $reference,
                    narration: $memo,
                    prefix: (string) config('accounting.sequences.misc', 'MISC'),
                );
            });
        } catch (Throwable) {
            // Never break recording the money because the books hiccuped; the
            // settlement itself is already saved.
        }
    }

    private function clear(string $reference): void
    {
        JournalEntry::query()->where('reference', $reference)->get()->each(function (JournalEntry $entry): void {
            $entry->items()->delete();
            $entry->delete();
        });
    }
}

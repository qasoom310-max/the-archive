<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalPoster;
use Modules\Pos\Events\PosOrderPaid;
use Throwable;

/**
 * A remote / delivery order's delivery fee is OUR cost — we pay the driver, it
 * isn't charged to the customer. When the order is paid, book:
 *
 *     Dr  Operating Expenses (5030)   delivery_fee
 *         Cr  Cash (1010)                     delivery_fee
 *
 * so the delivery cost lands in the P&L and reduces cash. Keyed by
 * `DEL/{reference}` so a re-fire replaces rather than duplicates it.
 * Best-effort — logged to the order's Chatter, never rethrown.
 */
final class RecordDeliveryCostInJournal
{
    public function __construct(private readonly JournalPoster $poster)
    {
    }

    public function handle(PosOrderPaid $event): void
    {
        $order = $event->order;

        try {
            $fee = round((float) $order->delivery_fee, 2);
            if ($fee <= 0.0) {
                return;
            }

            $reference = "DEL/{$order->reference}";

            $expense = Account::byCode((string) config('accounting.accounts.operating_expense'));
            $cash = Account::byCode((string) config('accounting.accounts.cash'));

            // On a delivery sale we never hand over cash for this: the company
            // keeps its fee out of what it collected, so the fee reduces what
            // they still owe us ("money in transit") rather than our drawer.
            // That also makes the transit balance settle to exactly the payout
            // we expect. Falls back to cash when the account isn't present.
            if ($order->isRemote()) {
                $transitCode = (string) config('accounting.accounts.money_in_transit', '');
                $transit = $transitCode !== '' ? Account::byCode($transitCode) : null;

                if ($transit !== null) {
                    $cash = $transit;
                }
            }

            if ($expense === null || $cash === null) {
                return;
            }

            $memo = "Delivery cost {$order->reference}";

            // Replacing the previous entry deletes a POSTED one, so the delete
            // and the rewrite have to be a single operation — outside a
            // transaction a rewrite that stopped part-way left the books
            // permanently short an entry and silently moved the Trial Balance.
            $entry = DB::transaction(function () use ($order, $expense, $cash, $fee, $reference, $memo): JournalEntry {
                $this->clear($reference);

                return $this->poster->record(
                    date: Carbon::parse($order->ordered_at ?? Carbon::now()),
                    lines: [
                        ['account_id' => (int) $expense->id, 'debit' => $fee, 'partner_id' => $order->partner_id, 'memo' => $memo],
                        ['account_id' => (int) $cash->id, 'credit' => $fee, 'partner_id' => $order->partner_id, 'memo' => $memo],
                    ],
                    reference: $reference,
                    narration: $memo,
                    prefix: (string) config('accounting.sequences.expense', 'EXP'),
                );
            });

            $order->logChange("Accounting: delivery cost posted {$entry->number} ({$fee}).");
        } catch (Throwable $e) {
            $order->logChange("Delivery-cost entry skipped: {$e->getMessage()}");
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

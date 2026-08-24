<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\JournalPoster;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Models\PosOrder;
use Throwable;

/**
 * Auto-posting: every paid POS order books
 *
 *     Dr  Cash               total
 *         Cr  Sales tax payable*      tax_total   (only when configured)
 *         Cr  Sales income            total - tax
 *
 * *Tax is posted only when an `accounting.accounts.sales_tax_payable`
 * mapping exists; otherwise the tax amount is folded into sales income
 * (single-rate dev/demo setups). Either way the entry stays balanced.
 *
 * Errors are caught and logged to the order's Chatter — a missing COA
 * row should NEVER break checkout. The DB sale is already committed
 * when this listener runs.
 */
final class RecordPosSaleInJournal
{
    public function __construct(
        private readonly JournalPoster $poster,
    ) {
    }

    public function handle(PosOrderPaid $event): void
    {
        $order = $event->order;

        try {
            $this->record($order);
        } catch (Throwable $e) {
            // Also to the application log: a note on one order is easy to miss,
            // and a silently unposted sale drifts the books with no warning.
            Log::error('POS sale not posted to the journal', [
                'order' => $order->reference,
                'error' => $e->getMessage(),
            ]);
            $order->logChange("Accounting entry skipped: {$e->getMessage()}");
        }
    }

    private function record(PosOrder $order): void
    {
        // A DELIVERY sale's money never reaches us at the till — the delivery
        // company collects it (or the customer transfers it), so it is held by
        // someone else until the payout lands. Debit "money in transit" for
        // those; a walk-in sale is real cash in the drawer. Falls back to cash
        // if the transit account isn't in this database's chart of accounts.
        $cashCode = (string) config('accounting.accounts.cash');
        $salesCode = (string) config('accounting.accounts.sales_income');

        $cash = Account::byCode($cashCode);
        $sales = Account::byCode($salesCode);

        if ($order->isRemote()) {
            $transitCode = (string) config('accounting.accounts.money_in_transit', '');
            $transit = $transitCode !== '' ? Account::byCode($transitCode) : null;

            if ($transit !== null) {
                $cash = $transit;
            }
        }

        if ($cash === null) {
            throw new \RuntimeException("Cash account '{$cashCode}' not found in Chart of Accounts.");
        }
        if ($sales === null) {
            throw new \RuntimeException("Sales income account '{$salesCode}' not found in Chart of Accounts.");
        }

        $total = round((float) $order->total, 2);
        $tax = round((float) $order->tax_total, 2);

        if ($total <= 0.0) {
            return;
        }

        $lines = [
            // Debit cash for the full tendered amount.
            [
                'account_id' => (int) $cash->id,
                'debit' => $total,
                'partner_id' => $order->partner_id,
                'memo' => "POS sale {$order->reference}",
            ],
        ];

        $taxAccountCode = (string) config('accounting.accounts.sales_tax_payable', '');
        $taxAccount = $taxAccountCode !== '' ? Account::byCode($taxAccountCode) : null;

        if ($tax > 0.0 && $taxAccount !== null) {
            // Split: tax to the payable, the REST to sales.
            //
            // The credit side must add up to what was actually collected, and
            // `subtotal` + `tax` is the list price — it ignores the customer
            // discount, the prepaid credit drawn, and any delivery charge. Any
            // one of those made the entry unbalanced, so JournalPoster refused
            // it and the sale posted NOTHING (the error was only ever written
            // to the order's notes). Deriving sales income from the total keeps
            // the tax figure honest and the entry always balanced: a discount
            // simply reduces recognised revenue, which is what it is.
            $taxLeg = min($tax, $total);
            $salesLeg = round($total - $taxLeg, 2);

            if ($salesLeg > 0.0) {
                $lines[] = [
                    'account_id' => (int) $sales->id,
                    'credit' => $salesLeg,
                    'partner_id' => $order->partner_id,
                    'memo' => "POS sale {$order->reference}",
                ];
            }

            $lines[] = [
                'account_id' => (int) $taxAccount->id,
                'credit' => $taxLeg,
                'partner_id' => $order->partner_id,
                'memo' => "Sales tax on {$order->reference}",
            ];
        } else {
            // No separate tax account configured — credit the full total
            // to sales. The taxes are still tracked on the POS order row
            // itself; this just means the books are kept at the gross
            // level. Easy to back-fill once a tax account is added.
            $lines[] = [
                'account_id' => (int) $sales->id,
                'credit' => $total,
                'partner_id' => $order->partner_id,
                'memo' => "POS sale {$order->reference}",
            ];
        }

        $date = $order->ordered_at ?? Carbon::now();

        $entry = $this->poster->record(
            date: Carbon::parse($date),
            lines: $lines,
            reference: $order->reference,
            narration: "POS sale {$order->reference}",
            prefix: (string) config('accounting.sequences.sales', 'SALE'),
        );

        $order->logChange("Accounting: posted {$entry->number} ({$total}).");
    }
}

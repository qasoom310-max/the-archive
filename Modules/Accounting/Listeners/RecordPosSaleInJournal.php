<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\JournalPoster;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Models\PosOrder;
use Throwable;

/**
 * Auto-posting: every paid POS order books
 *
 *     Dr  Cash               total
 *         Cr  Sales income            subtotal
 *         Cr  Sales tax payable*      tax_total   (only when configured)
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
        $subtotal = round((float) $order->subtotal, 2);
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
            // Split: net to sales, tax to payable. The sum (subtotal+tax)
            // equals total, keeping the entry balanced without juggling.
            $lines[] = [
                'account_id' => (int) $sales->id,
                'credit' => $subtotal,
                'partner_id' => $order->partner_id,
                'memo' => "POS sale {$order->reference}",
            ];
            $lines[] = [
                'account_id' => (int) $taxAccount->id,
                'credit' => $tax,
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

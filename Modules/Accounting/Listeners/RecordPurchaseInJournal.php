<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\JournalPoster;
use Throwable;

/**
 * Auto-posting: every confirmed vendor bill books
 *
 *     Dr  Inventory (or Purchase expense)   total
 *         Cr  Accounts payable                       total
 *
 * Routing rule: if the originating bill carries an inventory flag (e.g.
 * `is_stock_purchase = true`), the debit hits the Inventory asset
 * account; otherwise it hits the generic Purchase expense account.
 *
 * This listener is **event-shape agnostic** — the Purchases module
 * doesn't exist yet, but when it lands its `PurchaseInvoiceConfirmed`
 * event needs to expose:
 *
 *   - `$event->invoice->reference`     string
 *   - `$event->invoice->total`         float (2 dp)
 *   - `$event->invoice->partner_id`    int|null
 *   - `$event->invoice->confirmed_at`  ?Carbon
 *   - `$event->invoice->is_stock_purchase` bool
 *
 * Wire the listener up in
 * {@see \Modules\Accounting\Providers\AccountingServiceProvider::boot()}.
 *
 * Until the Purchases module exists, this listener is also reachable
 * via {@see record()} directly — useful for one-off scripts or tests.
 *
 * @phpstan-type Invoice object{
 *     reference: string,
 *     total: float,
 *     partner_id: int|null,
 *     confirmed_at: ?\Illuminate\Support\Carbon,
 *     is_stock_purchase: bool
 * }
 */
final class RecordPurchaseInJournal
{
    public function __construct(
        private readonly JournalPoster $poster,
    ) {
    }

    /**
     * Generic event entry point. The Purchases module is expected to
     * dispatch an event whose only public surface is an `$invoice`
     * property matching the @phpstan-type shape above; this method
     * unwraps it and delegates to {@see record()}.
     */
    public function handle(object $event): void
    {
        // Defensive duck-typing — the event class lives in another module
        // that may not be installed yet. We deliberately avoid a hard
        // import.
        if (! property_exists($event, 'invoice')) {
            return;
        }

        try {
            /** @var Invoice $invoice */
            $invoice = $event->invoice;
            $this->record($invoice);
        } catch (Throwable $e) {
            // No order Chatter to log to here (event shape is generic),
            // so use Laravel's logger. Cashier-side failure semantics
            // don't apply — a back-office confirm action can surface the
            // error to the user via the form save flow.
            logger()->error('Purchase posting failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Direct entry — useful for tests and for callers that want to
     * post a purchase without the event indirection.
     *
     * @param  Invoice  $invoice
     */
    public function record(object $invoice): void
    {
        $apCode = (string) config('accounting.accounts.accounts_payable');
        $ap = Account::byCode($apCode);

        if ($ap === null) {
            throw new \RuntimeException("Accounts Payable account '{$apCode}' not found in Chart of Accounts.");
        }

        $debitCode = $invoice->is_stock_purchase
            ? (string) config('accounting.accounts.inventory')
            : (string) config('accounting.accounts.purchase_expense');

        $debit = Account::byCode($debitCode);

        if ($debit === null) {
            throw new \RuntimeException("Purchase debit account '{$debitCode}' not found in Chart of Accounts.");
        }

        $total = round((float) $invoice->total, 2);

        if ($total <= 0.0) {
            return;
        }

        $date = $invoice->confirmed_at ?? Carbon::now();

        $this->poster->record(
            date: Carbon::parse($date),
            lines: [
                [
                    'account_id' => (int) $debit->id,
                    'debit' => $total,
                    'partner_id' => $invoice->partner_id,
                    'memo' => "Vendor bill {$invoice->reference}",
                ],
                [
                    'account_id' => (int) $ap->id,
                    'credit' => $total,
                    'partner_id' => $invoice->partner_id,
                    'memo' => "Vendor bill {$invoice->reference}",
                ],
            ],
            reference: $invoice->reference,
            narration: "Vendor bill {$invoice->reference}",
            prefix: (string) config('accounting.sequences.purchase', 'PURC'),
        );
    }
}

<?php

declare(strict_types=1);

namespace Modules\Purchases\Events;

use Modules\Purchases\Models\Purchase;

/**
 * Fired once a vendor bill is confirmed (after its stock-receipt transaction
 * commits). The Accounting module listens for this and books
 * Dr Inventory|Purchase Expense / Cr Accounts Payable.
 *
 * The `$invoice` property name + the Purchase model's columns deliberately
 * match the `@phpstan-type Invoice` shape that
 * {@see \Modules\Accounting\Listeners\RecordPurchaseInJournal} expects
 * (`reference`, `total`, `partner_id`, `confirmed_at`, `is_stock_purchase`),
 * so the listener consumes a Purchase with no adapter.
 */
final class PurchaseInvoiceConfirmed
{
    public function __construct(
        public readonly Purchase $invoice,
    ) {
    }
}

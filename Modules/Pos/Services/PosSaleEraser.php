<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\JournalEntry;
use Modules\Pos\Models\PosOrder;

/**
 * Permanently erase a POS order and everything derived from it — used to clear
 * TEST sales made while a shop is being set up. Removes the order, its lines,
 * its payments, and the journal entries it generated (the sale entry, keyed on
 * the order reference, plus the delivery-cost entry "DEL/<reference>" for
 * remote orders).
 *
 * Stock is deliberately NOT restored: deleting a mistaken/test sale does not put
 * consumed ingredients back — on-hand is corrected separately in the Stock
 * Report. Sessions are left intact.
 *
 * The derived-record cleanup lives in `purgeDerived()`, which `PosOrder`'s
 * `deleting` model event invokes. That indirection is deliberate: the ledger has
 * to be cleaned up no matter WHICH code path deletes the order. While the logic
 * sat only in `erase()`, it ran only for callers that remembered to use this
 * service — the engine's generic `ListView` bulk delete did not, and silently
 * left every journal entry behind, so Accounting kept counting revenue for sales
 * whose orders no longer existed.
 */
final class PosSaleEraser
{
    /**
     * Delete the order. Its lines, payments and journal entries are removed by
     * the `deleting` hook on PosOrder, so they go regardless of the caller.
     */
    public function erase(PosOrder $order): void
    {
        DB::transaction(static function () use ($order): void {
            $order->delete();
        });
    }

    /**
     * Remove everything derived from an order, but NOT the order row itself.
     *
     * Called from `PosOrder::booted()`'s `deleting` hook. Don't call it
     * directly unless you are about to delete the order yourself — on its own
     * it strips a live order of its lines, payments and ledger entries.
     */
    public function purgeDerived(PosOrder $order): void
    {
        // Accounting is an optional module: on a database where it was never
        // installed the table is absent and there is nothing to unwind.
        if (Schema::hasTable('journal_entries')) {
            $refs = [$order->reference, 'DEL/' . $order->reference];
            foreach (JournalEntry::query()->whereIn('reference', $refs)->get() as $entry) {
                $entry->items()->delete();
                $entry->delete();
            }
        }

        $order->payments()->delete();
        $order->lines()->delete();
    }
}

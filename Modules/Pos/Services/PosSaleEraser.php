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
 */
final class PosSaleEraser
{
    public function erase(PosOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            if (Schema::hasTable('journal_entries')) {
                $refs = [$order->reference, 'DEL/' . $order->reference];
                foreach (JournalEntry::query()->whereIn('reference', $refs)->get() as $entry) {
                    $entry->items()->delete();
                    $entry->delete();
                }
            }

            $order->payments()->delete();
            $order->lines()->delete();
            $order->delete();
        });
    }
}

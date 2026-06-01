<?php

declare(strict_types=1);

namespace Modules\Pos\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Enums\PrepStatus;
use Modules\Pos\Events\PosOrderPaid;

/**
 * On every finalised sale, stamp `prep_status = pending` + `prep_sent_at`
 * on each order line whose product's category routes to a KDS station.
 * Lines whose category has no station are left untouched (status stays
 * null) and won't show up on any kitchen screen.
 *
 * Fires AFTER `PosOrder::finalizeSale()` commits (the event is dispatched
 * outside the transaction by design), so the lines are durable when we
 * read them here. Errors are logged to the order's Chatter so a misrouted
 * category never blocks checkout — same convention as the WhatsApp listener.
 *
 * Wired in `PosServiceProvider::boot()`.
 */
final class QueueLinesForKitchen
{
    public function handle(PosOrderPaid $event): void
    {
        $order = $event->order;
        $lines = $order->lines()->get();

        if ($lines->isEmpty()) {
            return;
        }

        // Single DB pass for the station lookup: pluck distinct category
        // ids on this order, fetch them with their station in one query,
        // then map back. Beats N+1 across product → category.
        $productIds = $lines
            ->pluck('pos_product_id')
            ->filter()
            ->unique()
            ->all();

        if ($productIds === []) {
            return;
        }

        // Pluck via the QUERY BUILDER (DB::table, not Eloquent) so the
        // `station` Attribute accessor on PosCategory doesn't hydrate
        // each value into a PrepStation enum — we only need the raw
        // string here, and the map closure below carries a `?string`
        // return type that would TypeError on an enum instance.
        /** @var array<int, string|null> $stationByCategory */
        $stationByCategory = DB::table('pos_categories')
            ->whereIn('id', function ($q) use ($productIds): void {
                $q->select('pos_category_id')
                    ->from('pos_products')
                    ->whereIn('id', $productIds);
            })
            ->whereNotNull('station')
            ->pluck('station', 'id')
            ->all();

        /** @var array<int, string|null> $productToStation */
        $productToStation = DB::table('pos_products')
            ->whereIn('id', $productIds)
            ->pluck('pos_category_id', 'id')
            ->map(static fn ($catId): ?string => $catId !== null ? ($stationByCategory[(int) $catId] ?? null) : null)
            ->all();

        $now = Carbon::now();
        $queuedCount = 0;

        foreach ($lines as $line) {
            if ($line->pos_product_id === null) {
                continue;
            }

            $station = $productToStation[$line->pos_product_id] ?? null;

            if ($station === null) {
                continue;
            }

            // Idempotent — if the line was already pushed (e.g. event
            // fires twice), don't reset its station-side progress.
            if ($line->prep_status !== null) {
                continue;
            }

            $line->prep_status = PrepStatus::Pending;
            $line->prep_sent_at = $now;
            $line->save();
            $queuedCount++;
        }

        if ($queuedCount > 0) {
            $order->logChange("Sent {$queuedCount} item(s) to the kitchen / shisha screens.");
        }
    }
}

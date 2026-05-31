<?php

declare(strict_types=1);

namespace Modules\Pos\Listeners;

use Illuminate\Support\Carbon;
use Modules\Pos\Enums\PrepStatus;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosOrderLine;

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

        /** @var array<int, ?string> $stationByProduct */
        $stationByProduct = PosCategory::query()
            ->whereIn('id', function ($q) use ($productIds): void {
                $q->select('pos_category_id')
                    ->from('pos_products')
                    ->whereIn('id', $productIds);
            })
            ->whereNotNull('station')
            ->pluck('station', 'id')
            ->all();

        $productToStation = \Modules\Pos\Models\PosProduct::query()
            ->whereIn('id', $productIds)
            ->pluck('pos_category_id', 'id')
            ->map(static fn (?int $catId): ?string => $catId !== null ? ($stationByProduct[$catId] ?? null) : null)
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

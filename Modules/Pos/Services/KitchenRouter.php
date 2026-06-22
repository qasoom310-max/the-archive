<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Enums\PrepStatus;
use Modules\Pos\Models\PosOrder;

/**
 * Sole entry point for "send to kitchen". Stamps `prep_status = pending` +
 * `prep_sent_at` on every order line whose product's category carries a KDS
 * station (kitchen / shisha), skipping lines already routed (idempotent).
 * Returns the number of lines newly pushed.
 *
 * Called from TWO places so the logic can't drift:
 *  - {@see \Modules\Pos\Livewire\PosTerminal::addProduct()} — auto-sends each
 *    item the moment it's added (the postpaid dine-in flow: an item reaches
 *    the cook immediately, no payment required first).
 *  - {@see \Modules\Pos\Listeners\QueueLinesForKitchen} — a safety net on the
 *    paid event, in case a sale was finalised without passing through the
 *    terminal's add path (e.g. an order built programmatically). Idempotent,
 *    so it's a no-op once the terminal already routed everything.
 */
final class KitchenRouter
{
    public function route(PosOrder $order): int
    {
        $lines = $order->lines()->get();

        if ($lines->isEmpty()) {
            return 0;
        }

        $productIds = $lines
            ->pluck('pos_product_id')
            ->filter()
            ->unique()
            ->all();

        if ($productIds === []) {
            return 0;
        }

        // Query-builder plucks (DB::table, not Eloquent) so the PosCategory
        // `station` Attribute accessor doesn't hydrate values into PrepStation
        // enums — we only need the raw station string here, and the map
        // closure below is typed `?string`.
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
        $queued = 0;

        foreach ($lines as $line) {
            if ($line->pos_product_id === null) {
                continue;
            }

            $station = $productToStation[$line->pos_product_id] ?? null;

            if ($station === null) {
                continue;
            }

            // Idempotent — never reset a line that's already on a screen.
            if ($line->prep_status !== null) {
                continue;
            }

            $line->prep_status = PrepStatus::Pending;
            $line->prep_sent_at = $now;
            $line->save();
            $queued++;
        }

        return $queued;
    }
}

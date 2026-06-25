<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment status is now derived from the advance vs the total, but that only
 * recomputes when an order is saved — so orders created before the change kept
 * their old (often wrong) status. Backfill them once: a full advance reads Paid,
 * part Partial, none Unpaid. (payment_confirmed stays false, so a now-Paid order
 * shows "Paid · unconfirmed" until an accountant confirms it.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_orders', 'payment_status')) {
            return;
        }

        DB::table('rental_orders')->orderBy('id')->each(function (object $row): void {
            $total = (float) ($row->total ?? 0);
            $advance = (float) ($row->advance_amount ?? 0);

            $status = match (true) {
                $total <= 0.0 || $advance >= $total => 'paid',
                $advance > 0.0 => 'partial',
                default => 'unpaid',
            };

            if (($row->payment_status ?? null) !== $status) {
                DB::table('rental_orders')->where('id', $row->id)->update(['payment_status' => $status]);
            }
        });
    }

    public function down(): void
    {
        // No-op: re-deriving on the next save keeps it correct; we don't restore
        // the previous (stale) values.
    }
};

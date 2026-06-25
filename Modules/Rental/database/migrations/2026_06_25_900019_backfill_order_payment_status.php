<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment status is now derived from the advance vs the total, but that only
 * recomputes when an order is saved — so orders created before the change kept
 * their old (often wrong) status. Backfill them once with a single idempotent
 * UPDATE (a full advance reads Paid, part Partial, none Unpaid). payment_confirmed
 * stays false, so a now-Paid order shows "Paid · unconfirmed" until confirmed.
 *
 * A plain CASE statement (not a row loop) so it can't partially run and works on
 * MySQL and SQLite alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_orders', 'payment_status')) {
            return;
        }

        DB::statement(
            "UPDATE rental_orders SET payment_status = CASE
                WHEN total <= 0 OR advance_amount >= total THEN 'paid'
                WHEN advance_amount > 0 THEN 'partial'
                ELSE 'unpaid'
            END"
        );
    }

    public function down(): void
    {
        // No-op: the status re-derives on the next save anyway.
    }
};

<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Support\LegacyInvoiceBookings;

/**
 * Second pass at invoices printing as bare "Limousine services":
 *
 *  - an old-system invoice whose booking list the old export cut off
 *    mid-number ("…, 15225, 152") gets the stub dropped and its hidden trips
 *    found again when they add up exactly to the invoice;
 *  - invoices raised in this ERP that the 22 Sep restore brought back without
 *    their booking are re-tied to it (same customer, made that day, same
 *    amount, no invoice of its own — never guessed between unequal counts);
 *  - then the single-booking old invoices, as 2026_10_06_950041.
 *
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_invoices') || ! Schema::hasColumn('limo_bookings', 'imported_at')) {
            return;
        }

        $completed = LegacyInvoiceBookings::completeTruncated();
        $restored = LegacyInvoiceBookings::linkRestored();
        $waiting = LegacyInvoiceBookings::linkWaiting();

        if ($completed + $restored + $waiting === 0) {
            return;
        }

        $summary = "cut-off booking lists repaired: {$completed}; ERP invoices re-tied to their booking: {$restored}; old invoices linked: {$waiting}";
        Log::info('Relinked limousine invoices', ['summary' => $summary]);
        app(ActivityLogger::class)->log('updated', 'Invoices', ucfirst($summary));
    }

    public function down(): void
    {
        // Data only.
    }
};

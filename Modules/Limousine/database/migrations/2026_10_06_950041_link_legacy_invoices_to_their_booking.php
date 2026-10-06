<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Support\LegacyInvoiceBookings;

/**
 * Invoices brought over from the old system before their booking was
 * imported were left with no booking — only "Invoice #1340 | Bookings:
 * 15523" in their notes — so every screen and PDF showed them as a bare
 * "Limousine services" line with no trip, date, company ref. or passenger.
 * The bookings are on file now; link them (and claim their unlinked
 * receipts), exactly as the invoice importer does when the booking comes
 * first. Invoices spanning several bookings stay unlinked; the combined
 * invoice reads their trips from the notes. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_invoices') || ! Schema::hasColumn('limo_invoices', 'quotation_id')) {
            return;
        }

        $linked = LegacyInvoiceBookings::linkWaiting();
        if ($linked === 0) {
            return;
        }

        Log::info('Linked legacy limousine invoices to their booking', ['linked' => $linked]);
        app(ActivityLogger::class)->log('updated', 'Invoices', 'Old-system invoices linked to their booking: ' . $linked);
    }

    public function down(): void
    {
        // Data only.
    }
};

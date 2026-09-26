<?php

declare(strict_types=1);

use App\Erp\Backup\DatabaseBackup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Rental\Models\RentalInvoice;

/**
 * Limousine receipts ("L-RCPT…", the old limousine system's own numbering)
 * were loaded into the RENT A CAR receipts table by the historical data
 * import, so they showed on the Rent A Car receipts page. A rental receipt is
 * always numbered "RCP…"; an "L-RCPT…" row there is never a rental payment.
 *
 * Removes them, and re-totals any rental invoice one was attached to so its
 * paid amount and status reflect only its real rental receipts. A database
 * backup is taken first (Activity Log → Backups) so the rows can be recovered.
 * Idempotent: a database with nothing to remove is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_receipts')) {
            return;
        }

        $rows = DB::table('rental_receipts')->where('reference', 'like', 'L-RCPT%');
        if (! $rows->exists()) {
            return;
        }

        try {
            app(DatabaseBackup::class)->snapshot();
        } catch (Throwable $e) {
            // Never delete without a way back.
            Log::error('Skipped removing limousine receipts from rental: backup failed', ['error' => $e->getMessage()]);

            return;
        }

        $invoiceIds = (clone $rows)->whereNotNull('invoice_id')->distinct()->pluck('invoice_id')->all();
        $count = (clone $rows)->count();

        (clone $rows)->delete();

        foreach (RentalInvoice::query()->whereKey($invoiceIds)->get() as $invoice) {
            $invoice->recomputePaid();
        }

        Log::info('Removed limousine receipts from the rental receipts table', [
            'database' => DB::connection()->getDatabaseName(),
            'removed' => $count,
            'invoices_retotalled' => count($invoiceIds),
        ]);
    }

    public function down(): void
    {
        // Restore from the backup taken in up() if these rows are ever needed.
    }
};

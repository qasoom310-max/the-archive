<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Support\LiveEntry;

/**
 * An invoice used to stop following its booking the moment any money landed
 * on it, so every booking re-priced after its deposit kept an invoice for the
 * old amount — and the office could not raise a correct one, because a
 * booking has exactly one invoice. Invoices now follow their booking paid or
 * not; this brings the ones already out of step back in line.
 *
 * Only bookings entered in this ERP (not brought over from the old system,
 * whose invoices carry the old system's own figures), and only where the
 * booking has a price. Every change is logged with the old and new total.
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_invoices') || ! Schema::hasColumn('limo_bookings', 'imported_at')) {
            return;
        }

        $ids = DB::table('limo_invoices as i')
            ->join('limo_bookings as b', 'b.id', '=', 'i.booking_id')
            ->whereNull('b.imported_at')
            ->where('b.created_at', '>=', LiveEntry::since())
            ->where('b.fare', '>', 0)
            ->whereRaw('ABS(ROUND(i.total, 3) - ROUND(b.fare, 3)) >= 0.0005')
            ->pluck('i.id');

        $changes = [];
        foreach ($ids as $id) {
            $invoice = LimoInvoice::query()->with('booking')->find($id);
            $booking = $invoice?->booking;
            if ($invoice === null || ! $booking instanceof LimoBooking) {
                continue;
            }

            $before = round((float) $invoice->total, 3);
            if ($invoice->followTotal((float) $booking->fare)) {
                $booking->syncPaymentFromAdvance();
                $changes[] = sprintf('%s: %.3f → %.3f', (string) $invoice->reference, $before, round((float) $invoice->total, 3));
            }
        }

        if ($changes === []) {
            return;
        }

        Log::info('Limousine invoices brought in step with their booking', ['changes' => $changes]);
        app(ActivityLogger::class)->log(
            'updated',
            'Invoices',
            sprintf('%d invoice total(s) corrected to their booking: %s', count($changes), implode('; ', $changes)),
        );
    }

    public function down(): void
    {
        // Data only.
    }
};

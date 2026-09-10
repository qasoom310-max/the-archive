<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every trip already on file gets its invoice.
 *
 * Invoices used to be raised by pressing a button, so most bookings never got
 * one — money was taken against trips that were never billed. From now on the
 * invoice is issued with the booking, but that only helps what comes next: the
 * history would sit outside the chain, and the reports would show a gap where
 * the old trips were.
 *
 * Money already received is matched onto the new invoice, so a trip that was
 * paid for reads as paid rather than as a fresh debt.
 *
 * Cancelled trips are included deliberately: they are often part-paid, and an
 * unbilled payment is exactly the thing this is meant to stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_bookings') || ! Schema::hasTable('limo_invoices')) {
            return;
        }

        $invoiced = DB::table('limo_invoices')->whereNotNull('booking_id')->pluck('booking_id')->all();

        DB::table('limo_bookings')
            ->when($invoiced !== [], fn ($q) => $q->whereNotIn('id', $invoiced))
            ->orderBy('id')
            ->chunkById(200, function ($bookings): void {
                foreach ($bookings as $booking) {
                    $this->invoice($booking);
                }
            });
    }

    private function invoice(object $booking): void
    {
        $total = round((float) ($booking->fare ?? 0), 3);

        // What was actually received against this trip, from the receipts that
        // already exist. Falls back to the advance for older bookings whose
        // money predates receipts being written at all.
        $paid = round((float) DB::table('limo_receipts')
            ->where('booking_id', $booking->id)
            ->sum('amount'), 3);

        if ($paid <= 0) {
            $paid = round((float) ($booking->advance ?? 0), 3);
        }

        $paid = min($paid, $total);

        $id = DB::table('limo_invoices')->insertGetId([
            'customer_id' => $booking->customer_id,
            'booking_id' => $booking->id,
            // Dated to the trip, not to today: an invoice stamped with the day
            // this migration ran would put years of history into one month.
            'issue_date' => $booking->pickup_at ?? $booking->created_at ?? Carbon::now(),
            'due_date' => $booking->pickup_at ?? $booking->created_at ?? Carbon::now(),
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'amount_paid' => $paid,
            'status' => match (true) {
                $paid <= 0 => 'unpaid',
                $paid + 0.0005 < $total => 'partial',
                default => 'paid',
            },
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // The reference the rest of the app shows. Matches HasReference's
        // format, generated here because a raw insert never fires the hook.
        DB::table('limo_invoices')->where('id', $id)->update([
            'reference' => 'INV/' . str_pad((string) $id, 5, '0', STR_PAD_LEFT),
        ]);

        // Receipts already written against this trip now point at its invoice,
        // so the document and the money agree.
        DB::table('limo_receipts')
            ->where('booking_id', $booking->id)
            ->whereNull('invoice_id')
            ->update(['invoice_id' => $id]);
    }

    public function down(): void
    {
        // Deliberately irreversible: nothing records which invoices this
        // created, and guessing would delete ones raised by hand since.
    }
};

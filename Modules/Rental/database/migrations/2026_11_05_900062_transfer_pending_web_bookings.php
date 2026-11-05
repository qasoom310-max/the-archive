<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Rental\Models\RentalWebBooking;
use Modules\Rental\Services\WebBookingAutoTransfer;

/**
 * Website bookings now move to Limousine → Bookings / Rent A Car → Orders as
 * they arrive. This does the same, once, for those already waiting on the Web
 * bookings page:
 *  - payment-link orders are marked complete (their trip is already booked);
 *  - a booking whose trip or hire is today or later is moved;
 *  - a booking for a day already past is left where it is: moving it would
 *    put a finished job in the queue. The desk can still open it there.
 * Unpaid card attempts stay too, as on arrival.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_web_bookings') || ! Schema::hasColumn('rental_web_bookings', 'limo_booking_id')) {
            return;
        }

        $transfer = app(WebBookingAutoTransfer::class);
        $today = now()->startOfDay();
        $counts = [];

        $pending = RentalWebBooking::query()
            ->where('status', RentalWebBooking::STATUS_PENDING)
            ->whereNull('rental_order_id')
            ->whereNull('limo_booking_id')
            ->orderBy('id')
            ->get();

        foreach ($pending as $web) {
            if (! $web->isPaymentLink() && ($web->pickup_at === null || $web->pickup_at->lt($today))) {
                $counts['past'] = ($counts['past'] ?? 0) + 1;

                continue;
            }

            try {
                $result = $transfer->handle($web);
            } catch (\Throwable $e) {
                $result = 'failed';
                Log::warning('Web booking ' . $web->source_reference . ' was not moved: ' . $e->getMessage());
            }
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }

        Log::info('Pending web bookings moved on: ' . json_encode($counts));
    }

    public function down(): void
    {
        // Bookings and orders made from website bookings stay; nothing to undo.
    }
};

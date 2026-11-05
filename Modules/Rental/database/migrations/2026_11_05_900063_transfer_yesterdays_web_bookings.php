<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Rental\Models\RentalWebBooking;
use Modules\Rental\Services\WebBookingAutoTransfer;

/**
 * The first pass (900062) moved waiting website bookings from today on. The
 * owner wants yesterday's too, so they show on the Limousine dashboard's
 * Yesterday card: a customer booked for yesterday and nobody knew. Older days
 * stay on the Web bookings page. Unpaid card attempts still wait, as always.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_web_bookings') || ! Schema::hasColumn('rental_web_bookings', 'limo_booking_id')) {
            return;
        }

        $transfer = app(WebBookingAutoTransfer::class);
        $counts = [];

        $waiting = RentalWebBooking::query()
            ->where('status', RentalWebBooking::STATUS_PENDING)
            ->whereNull('rental_order_id')
            ->whereNull('limo_booking_id')
            ->where('pickup_at', '>=', now()->subDay()->startOfDay())
            ->where('pickup_at', '<', now()->startOfDay())
            ->orderBy('id')
            ->get();

        foreach ($waiting as $web) {
            try {
                $result = $transfer->handle($web);
            } catch (\Throwable $e) {
                $result = 'failed';
                Log::warning('Web booking ' . $web->source_reference . ' was not moved: ' . $e->getMessage());
            }
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }

        Log::info("Yesterday's web bookings moved on: " . json_encode($counts));
    }

    public function down(): void
    {
    }
};

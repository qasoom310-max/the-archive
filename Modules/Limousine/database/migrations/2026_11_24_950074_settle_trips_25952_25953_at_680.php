<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use App\Erp\Backup\DatabaseBackup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * The manager accepted 680 BD as payment in full for the booking holding trips
 * 25952 (280) and 25953 (540): 820 billed, 680 received (RCP/25980 285 +
 * RCP/26033 395). The 140 difference is given as a DISCOUNT on trip 25953, not
 * recorded as money: no payment of 140 was received, and a receipt for it
 * would put money in the reports that never came in.
 *
 * Acts only on a booking that still matches exactly: these two trips and no
 * other billed trip, 25953 priced 540, a total of 820 and 680 received.
 * Anything else (another database, a booking already corrected) is left alone.
 */
return new class extends Migration
{
    private const DISCOUNT = 140.0;

    public function up(): void
    {
        if (! Schema::hasTable('limo_legs') || ! Schema::hasTable('limo_bookings')) {
            return;
        }

        $morph = (new LimoBooking())->getMorphClass();
        $legs = LimoLeg::query()
            ->where('legable_type', $morph)
            ->whereIn('reference', ['25952', '25953'])
            ->get();
        if ($legs->count() !== 2 || $legs->pluck('legable_id')->unique()->count() !== 1) {
            return;
        }

        $booking = LimoBooking::query()->find((int) $legs->first()->legable_id);
        $leg = $legs->firstWhere('reference', '25953');
        if ($booking === null || $leg === null) {
            return;
        }

        $billed = $booking->legs()->get()->filter(static fn (LimoLeg $l): bool => $l->isBillable());
        $matches = $billed->count() === 2
            && abs((float) $leg->net_amount - 540.0) < 0.001
            && abs((float) $booking->fare - 820.0) < 0.001
            && abs($booking->amountReceived() - 680.0) < 0.001;
        if (! $matches) {
            Log::warning('Left booking of trips 25952/25953 alone: it no longer matches', [
                'booking' => $booking->reference, 'fare' => (float) $booking->fare,
                'received' => $booking->amountReceived(), 'trip_25953' => (float) $leg->net_amount,
            ]);

            return;
        }

        app(DatabaseBackup::class)->snapshot();

        DB::transaction(function () use ($booking, $leg): void {
            $leg->discount = round((float) $leg->discount + self::DISCOUNT, 3);
            $leg->net_amount = round((float) $leg->net_amount - self::DISCOUNT, 3);
            $leg->saveQuietly();

            $booking->recalcTotal();
            $booking->save();
            $booking->syncPaymentFromAdvance();
            // The invoice follows the new price and works out its paid status
            // from its receipts, which marks the booking paid.
            $booking->syncInvoice();
        });

        $booking->refresh();
        Log::info('Booking of trips 25952/25953 settled at 680 with a 140 discount on trip 25953', [
            'booking' => $booking->reference, 'fare' => (float) $booking->fare, 'owed' => $booking->amountOwed(),
        ]);
        app(ActivityLogger::class)->log(
            'updated',
            'Booking ' . (string) $booking->reference,
            'Manager accepted 680 BD as full payment: 140 BD discount on trip 25953 (540 to 400), total 820 to 680',
        );
    }

    public function down(): void
    {
        // A price correction agreed by the manager is not undone by a rollback.
    }
};

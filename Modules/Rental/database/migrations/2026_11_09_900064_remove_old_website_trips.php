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
 * Takes the old website trips back off Limousine → Bookings (the owner,
 * 2026-11-09: they mixed in with the real work; only today's and later ones
 * belong there). From now on the auto-move refuses old ones too
 * ({@see \Modules\Rental\Services\WebBookingAutoTransfer::isOld()}).
 *
 * Removed only when nobody has touched the booking since it was moved:
 *  - moved automatically (prepared by "Website"), never converted by hand;
 *  - every trip's pick-up day is before today (no pick-up time: the booking
 *    itself is from before today);
 *  - no trip completed or under way, and none has a car, driver or another
 *    company's car on it;
 *  - no money anywhere: nothing received, no receipt, no paid invoice, no
 *    payment link, no expense, no coupon.
 * Anything else stays, and is listed in the log. The booking's own unpaid
 * invoice goes with it. The Web bookings row keeps its "complete" status and
 * loses the link, so it is never moved again. A backup is taken first.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['rental_web_bookings', 'limo_bookings', 'limo_legs'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }
        if (! Schema::hasColumn('rental_web_bookings', 'limo_booking_id') || ! class_exists(LimoBooking::class)) {
            return;
        }

        $today = now()->startOfDay();
        $morph = (new LimoBooking())->getMorphClass();

        $ids = DB::table('rental_web_bookings')->whereNotNull('limo_booking_id')
            ->pluck('limo_booking_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            return;
        }

        $remove = [];
        $kept = [];
        foreach (DB::table('limo_bookings')->whereIn('id', $ids)->get() as $booking) {
            if ((string) $booking->prepared_by !== 'Website') {
                continue;
            }
            $reason = $this->keepReason($booking, $morph, $today);
            if ($reason === null) {
                $remove[(int) $booking->id] = (string) $booking->reference;
            } elseif ($reason !== 'today or later') {
                $kept[] = $booking->reference . ' (' . $reason . ')';
            }
        }

        if ($kept !== []) {
            Log::info('Old website trips left on the bookings list: ' . implode(', ', $kept));
        }
        if ($remove === []) {
            return;
        }

        // No backup, no deletion — and the migration is not recorded as run,
        // so the next deploy tries again.
        app(DatabaseBackup::class)->snapshot();

        $bookingIds = array_keys($remove);
        DB::transaction(static function () use ($bookingIds, $morph): void {
            if (Schema::hasTable('limo_invoices')) {
                DB::table('limo_invoices')->whereIn('booking_id', $bookingIds)->delete();
            }
            DB::table('limo_legs')->where('legable_type', $morph)->whereIn('legable_id', $bookingIds)->delete();
            DB::table('rental_web_bookings')->whereIn('limo_booking_id', $bookingIds)->update(['limo_booking_id' => null]);
            DB::table('limo_bookings')->whereIn('id', $bookingIds)->delete();
        });

        $list = implode(', ', $remove);
        Log::info('Old website trips removed from the bookings list: ' . $list);
        app(ActivityLogger::class)->log('deleted', __('Limousine booking'), __('Old website trips removed') . ': ' . $list);
    }

    /** Null when the booking can go; otherwise why it stays. */
    private function keepReason(object $booking, string $morph, \Illuminate\Support\Carbon $today): ?string
    {
        $legs = DB::table('limo_legs')->where('legable_type', $morph)->where('legable_id', $booking->id)->get();
        $outside = Schema::hasColumn('limo_legs', 'outside_company');

        if ($legs->isEmpty() && ($booking->created_at === null || \Illuminate\Support\Carbon::parse((string) $booking->created_at)->gte($today))) {
            return 'today or later';
        }

        foreach ($legs as $leg) {
            $start = $leg->start_at !== null ? \Illuminate\Support\Carbon::parse((string) $leg->start_at) : null;
            if ($start !== null ? $start->gte($today) : ($booking->created_at === null || \Illuminate\Support\Carbon::parse((string) $booking->created_at)->gte($today))) {
                return 'today or later';
            }
            if (in_array($leg->status, [LimoLeg::STATUS_ACTIVE, LimoLeg::STATUS_COMPLETED], true)) {
                return 'trip ' . $leg->status;
            }
            if ($leg->car_id !== null || $leg->driver_id !== null || ($outside && $leg->outside_company !== null && $leg->outside_company !== '')) {
                return 'car or driver assigned';
            }
        }

        if ((float) ($booking->advance ?? 0) > 0) {
            return 'money received';
        }

        $invoiceIds = Schema::hasTable('limo_invoices')
            ? DB::table('limo_invoices')->where('booking_id', $booking->id)->pluck('id')->all()
            : [];
        if ($invoiceIds !== [] && DB::table('limo_invoices')->whereIn('id', $invoiceIds)->where('amount_paid', '>', 0)->exists()) {
            return 'invoice paid';
        }
        if (Schema::hasTable('limo_receipts') && DB::table('limo_receipts')
            ->where(static function ($q) use ($booking, $invoiceIds): void {
                $q->where('booking_id', $booking->id);
                if ($invoiceIds !== []) {
                    $q->orWhereIn('invoice_id', $invoiceIds);
                }
            })->exists()) {
            return 'receipt';
        }

        foreach (['limo_payment_links' => 'booking_id', 'limo_expenses' => 'booking_id', 'limo_coupons' => 'limo_booking_id', 'limo_coupon_redemptions' => 'limo_booking_id'] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)
                && DB::table($table)->where($column, $booking->id)->exists()) {
                return str_replace('limo_', '', $table);
            }
        }

        return null;
    }

    public function down(): void
    {
    }
};

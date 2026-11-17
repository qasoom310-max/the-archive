<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * About an hour before a trip booked on the website, emails the customer what
 * is still owed when they paid only part of it online.
 *
 * Only for bookings made from a website order (linked from
 * `rental_web_bookings.limo_booking_id`) that have received money and still
 * owe some. Once per booking (`balance_reminded_at`). Skipped when the booking
 * was itself made inside the hour: the customer was just emailed the receipt
 * with the balance on it. Run every five minutes per database by the scheduler;
 * the email itself obeys the "Email documents to customers" switch.
 */
final class BalanceReminders
{
    public const MINUTES_BEFORE = 60;

    public function __construct(private readonly CustomerDocumentEmails $emails) {}

    /** @return int how many reminders were sent */
    public function run(?CarbonInterface $now = null): int
    {
        if (! Schema::hasTable('rental_web_bookings') || ! Schema::hasColumn('limo_bookings', 'balance_reminded_at')) {
            return 0;
        }

        $now = Carbon::instance($now ?? Carbon::now());
        $windowEnd = $now->copy()->addMinutes(self::MINUTES_BEFORE);

        $web = DB::table('rental_web_bookings')
            ->whereNotNull('limo_booking_id')
            ->orderByDesc('id')
            ->get(['limo_booking_id', 'email']);
        $emails = [];
        foreach ($web as $row) {
            $emails[(int) $row->limo_booking_id] ??= trim((string) $row->email);
        }
        if ($emails === []) {
            return 0;
        }

        $legs = LimoLeg::query()
            ->where('legable_type', LimoBooking::class)
            ->whereIn('legable_id', array_keys($emails))
            ->whereIn('status', LimoLeg::OPEN_STATUSES)
            ->where('start_at', '>', $now)
            ->where('start_at', '<=', $windowEnd)
            ->orderBy('start_at')
            ->get();

        $sent = 0;
        $done = [];
        foreach ($legs as $leg) {
            $bookingId = (int) $leg->legable_id;
            if (isset($done[$bookingId])) {
                continue;
            }
            $done[$bookingId] = true;

            $booking = LimoBooking::query()->find($bookingId);
            if ($booking === null || $booking->balance_reminded_at !== null
                || $booking->status === LimoBooking::STATUS_CANCELLED) {
                continue;
            }

            // Paid part online and still owes: nothing to remind otherwise.
            if ($booking->amountReceived() <= 0.0005 || $booking->amountOwed() <= 0.0005) {
                continue;
            }

            // Booked inside the hour: the receipt email just told them.
            if ($booking->created_at !== null && $leg->start_at !== null
                && $booking->created_at->greaterThan($leg->start_at->copy()->subMinutes(self::MINUTES_BEFORE))) {
                continue;
            }

            // Claimed before sending, so two runs can never both send it.
            $claimed = LimoBooking::query()->whereKey($bookingId)->whereNull('balance_reminded_at')
                ->update(['balance_reminded_at' => $now]);
            if ($claimed !== 1) {
                continue;
            }

            $this->emails->balanceReminder((int) $leg->id, $emails[$bookingId] ?? null, now: true);
            $sent++;
        }

        return $sent;
    }
}

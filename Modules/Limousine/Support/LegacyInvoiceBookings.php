<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;

/**
 * The bookings an invoice brought over from the old system was raised for,
 * when it could not be linked to one booking.
 *
 * {@see LegacyInvoiceImporter} keeps them in `notes` as
 * "Invoice #1327 | Bookings: 15119, 15124, …" — the old system's own booking
 * numbers, which are the booking ids here. One number means the booking was
 * not on file yet at import time; several mean the old system billed several
 * trips on one invoice.
 */
final class LegacyInvoiceBookings
{
    /** @return list<int> */
    public static function ids(?string $notes): array
    {
        if ($notes === null || preg_match('/Bookings:\s*([\d,\s]+)/i', $notes, $match) !== 1) {
            return [];
        }

        $ids = array_map(static fn (string $piece): int => (int) trim($piece), explode(',', $match[1]));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Tie each invoice that was waiting for its ONE booking to that booking,
     * now that it is on file — what the importer would have done had the
     * booking come first: link it, and claim the booking's receipts that have
     * no invoice of their own. Skipped when the booking already has an
     * invoice, so no trip ends up billed twice. Returns how many were linked.
     */
    public static function linkWaiting(): int
    {
        $waiting = LimoInvoice::query()
            ->whereNull('booking_id')
            ->whereNull('quotation_id')
            ->where('notes', 'like', 'Invoice #%Bookings:%')
            ->get();

        $linked = 0;
        foreach ($waiting as $invoice) {
            $ids = self::ids($invoice->notes);
            if (count($ids) !== 1) {
                continue;
            }

            $bookingId = $ids[0];
            if (! LimoBooking::query()->whereKey($bookingId)->exists()
                || LimoInvoice::query()->where('booking_id', $bookingId)->exists()) {
                continue;
            }

            DB::transaction(static function () use ($invoice, $bookingId): void {
                $invoice->booking_id = $bookingId;
                $invoice->saveQuietly();

                $claimed = LimoReceipt::query()
                    ->where('booking_id', $bookingId)
                    ->whereNull('invoice_id')
                    ->update(['invoice_id' => $invoice->id]);

                if ($claimed > 0) {
                    $invoice->refresh()->recomputePaid();
                }
            });
            $linked++;
        }

        return $linked;
    }
}

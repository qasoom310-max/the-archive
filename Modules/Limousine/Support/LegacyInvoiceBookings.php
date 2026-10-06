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
            // The same customer's booking only: a number can be mistyped or
            // cut short in the old export and name someone else's trip.
            if (! LimoBooking::query()->whereKey($bookingId)->where('customer_id', $invoice->customer_id)->exists()
                || LimoInvoice::query()->where('booking_id', $bookingId)->exists()) {
                continue;
            }

            self::link($invoice, $bookingId);
            $linked++;
        }

        return $linked;
    }

    /**
     * Re-tie invoices raised in this ERP that lost their booking.
     *
     * After the 22 Sep wipe the invoices came back first through the plain
     * invoice import (number, customer, total — no booking), and restoring
     * the bookings then reused those invoices by number without linking them.
     * The booking is still recognisable: the ERP issues a booking's invoice
     * the moment the booking is saved, so it is the same customer's booking,
     * made on the invoice's issue date, for the invoice's amount, entered in
     * this ERP (not imported) and with no invoice of its own. Several equal
     * invoices on one day pair with as many such bookings in the order both
     * were made; any other count is left alone rather than guessed.
     */
    public static function linkRestored(): int
    {
        $orphans = LimoInvoice::query()
            ->whereNull('booking_id')
            ->whereNull('quotation_id')
            ->whereNotNull('issue_date')
            ->where(static fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', '%Bookings:%'))
            ->orderBy('id')
            ->get();

        if ($orphans->isEmpty()) {
            return 0;
        }

        $key = static fn (int $customer, ?string $day, float $amount): string => $customer . '|' . $day . '|' . number_format($amount, 3, '.', '');
        $invoicesByKey = $orphans->groupBy(static fn (LimoInvoice $i): string => $key((int) $i->customer_id, $i->issue_date?->toDateString(), (float) $i->total));

        // Bookings entered in this ERP first; then, for what is left, the old
        // system's copy of the same trip (the office ran both side by side,
        // and an old booking that had an ERP twin was the one imported when
        // the ERP one was not restored) — never one an old invoice names.
        $named = LimoInvoice::query()->where('notes', 'like', '%Bookings:%')->pluck('notes')
            ->flatMap(static fn (?string $notes): array => self::ids($notes))->flip();

        $linked = 0;
        foreach ([false, true] as $imported) {
            $invoiced = LimoInvoice::query()->whereNotNull('booking_id')->pluck('booking_id')->flip();
            $bookingsByKey = LimoBooking::query()
                ->whereIn('customer_id', $orphans->pluck('customer_id')->unique()->all())
                ->when($imported, static fn ($q) => $q->whereNotNull('imported_at'), static fn ($q) => $q->whereNull('imported_at'))
                ->where('status', '!=', LimoBooking::STATUS_CANCELLED)
                ->orderBy('id')
                ->get(['id', 'customer_id', 'fare', 'created_at'])
                ->reject(static fn (LimoBooking $b): bool => $invoiced->has($b->id) || ($imported && $named->has($b->id)))
                ->groupBy(static fn (LimoBooking $b): string => $key((int) $b->customer_id, $b->created_at?->toDateString(), (float) $b->fare));

            foreach ($invoicesByKey as $k => $invoices) {
                $bookings = $bookingsByKey->get($k);
                if ($bookings === null || $bookings->count() !== $invoices->count()) {
                    continue;
                }

                $bookings = $bookings->values();
                foreach ($invoices->values() as $i => $invoice) {
                    self::link($invoice, (int) $bookings[$i]->id);
                    $linked++;
                }
                $invoicesByKey->forget($k);
            }
        }

        return $linked;
    }

    /**
     * Finish a booking list the old system's export cut off mid-number
     * ("…, 15225, 152"). The stub is dropped — it names some other trip —
     * and the trips it hid are found again: the same customer's next bookings
     * by number that no other invoice covers, taken in order until they make
     * up exactly what the listed trips leave of the invoice total. Without an
     * exact match only the stub is dropped. Returns how many were corrected.
     */
    public static function completeTruncated(): int
    {
        $invoices = LimoInvoice::query()
            ->whereNull('booking_id')
            ->where('notes', 'like', 'Invoice #%Bookings:%')
            ->get();

        $corrected = 0;
        foreach ($invoices as $invoice) {
            if (preg_match('/^Invoice #(\d+) \| Bookings:\s*([\d,\s]+)$/', trim((string) $invoice->notes), $m) !== 1) {
                continue;
            }

            $tokens = array_values(array_filter(array_map('trim', explode(',', $m[2])), static fn (string $t): bool => $t !== ''));
            if (count($tokens) < 2 || strlen((string) end($tokens)) >= strlen($tokens[0])) {
                continue;
            }

            array_pop($tokens);
            $ids = array_map('intval', $tokens);

            $listed = (float) LimoBooking::query()->whereKey($ids)->where('customer_id', $invoice->customer_id)->sum('fare');
            $missing = round((float) $invoice->total - $listed, 3);

            if ($missing > 0.0005) {
                $elsewhere = LimoInvoice::query()->whereKeyNot($invoice->id)->where('notes', 'like', '%Bookings:%')->pluck('notes')
                    ->flatMap(static fn (?string $notes): array => self::ids($notes))->flip();
                $invoiced = LimoInvoice::query()->whereNotNull('booking_id')->pluck('booking_id')->flip();

                $found = [];
                $sum = 0.0;
                $next = LimoBooking::query()
                    ->where('customer_id', $invoice->customer_id)
                    ->where('id', '>', max($ids))
                    ->where('status', '!=', LimoBooking::STATUS_CANCELLED)
                    ->orderBy('id')
                    ->get(['id', 'fare']);
                foreach ($next as $booking) {
                    if ($elsewhere->has($booking->id) || $invoiced->has($booking->id)) {
                        continue;
                    }
                    $found[] = (int) $booking->id;
                    $sum = round($sum + (float) $booking->fare, 3);
                    if ($sum >= $missing - 0.0005) {
                        break;
                    }
                }

                if (abs($sum - $missing) < 0.0005) {
                    $ids = array_merge($ids, $found);
                }
            }

            $invoice->notes = sprintf('Invoice #%s | Bookings: %s', $m[1], implode(', ', $ids));
            $invoice->saveQuietly();
            $corrected++;
        }

        return $corrected;
    }

    /** Link, and claim the booking's receipts that have no invoice of their own. */
    private static function link(LimoInvoice $invoice, int $bookingId): void
    {
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
    }
}

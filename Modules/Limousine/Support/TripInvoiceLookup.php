<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Collection;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;

/**
 * Which invoice bills a trip, found from the trip number the office types.
 *
 * A trip is billed by its booking's invoice, or (old-system history) by an
 * invoice whose notes list the booking: "Invoice #1327 | Bookings: 15119, …".
 * The Invoices search uses it, and so does the note under the search that says
 * why a trip's invoice is not in the list (none yet, already in a bulk invoice,
 * outside the dates picked).
 */
final class TripInvoiceLookup
{
    /** A whole trip number, as typed: digits only. */
    public static function isTripNumber(string $term): bool
    {
        return preg_match('/^\d{3,9}$/', trim($term)) === 1;
    }

    /**
     * The booking trips with exactly this number (or that number before the
     * renumbering).
     *
     * @return Collection<int, LimoLeg>
     */
    public static function trips(string $term): Collection
    {
        $term = trim($term);
        if (! self::isTripNumber($term)) {
            return new Collection();
        }

        return LimoLeg::query()
            ->where('legable_type', (new LimoBooking())->getMorphClass())
            ->where(fn ($q) => $q->where('reference', $term)->orWhere('previous_reference', $term))
            ->orderBy('id')
            ->limit(10)
            ->get();
    }

    /**
     * Every invoice that bills one of these bookings.
     *
     * @param  list<int>  $bookingIds
     * @return array<int, list<int>> booking id => invoice ids
     */
    public static function invoicesByBooking(array $bookingIds): array
    {
        $out = array_fill_keys($bookingIds, []);
        if ($bookingIds === []) {
            return $out;
        }

        foreach (LimoInvoice::query()->whereIn('booking_id', $bookingIds)->get(['id', 'booking_id']) as $invoice) {
            $out[(int) $invoice->booking_id][] = (int) $invoice->id;
        }

        // Old-system invoices name their bookings in the notes.
        $legacy = LimoInvoice::query()->whereNull('booking_id')->where(function ($q) use ($bookingIds): void {
            foreach ($bookingIds as $id) {
                $q->orWhere('notes', 'like', '%Bookings:%' . $id . '%');
            }
        })->get(['id', 'notes']);
        foreach ($legacy as $invoice) {
            foreach (LegacyInvoiceBookings::ids($invoice->notes) as $id) {
                if (array_key_exists($id, $out) && ! in_array((int) $invoice->id, $out[$id], true)) {
                    $out[$id][] = (int) $invoice->id;
                }
            }
        }

        return $out;
    }

    /**
     * The invoices that bill the trip with this number.
     *
     * @return list<int>
     */
    public static function invoiceIds(string $term): array
    {
        $bookingIds = self::trips($term)->pluck('legable_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        return array_values(array_unique(array_merge([], ...array_values(self::invoicesByBooking($bookingIds)))));
    }

    /**
     * What to tell the office about each trip with this number: which invoice
     * bills it and whether that invoice can be ticked here.
     *
     * @param  list<int>  $listed  invoice ids the list is showing for this search
     * @return list<array{trip: string, booking_id: int, booking: string, cancelled: bool, invoices: list<array{reference: string, bulk: ?string, listed: bool}>}>
     */
    public static function hints(string $term, array $listed): array
    {
        $trips = self::trips($term);
        if ($trips->isEmpty()) {
            return [];
        }

        $bookingIds = $trips->pluck('legable_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $byBooking = self::invoicesByBooking($bookingIds);
        $bookings = LimoBooking::query()->whereIn('id', $bookingIds)->pluck('reference', 'id');
        $invoices = LimoInvoice::query()->with('bulkInvoice:id,reference')
            ->whereIn('id', array_merge([], ...array_values($byBooking)))
            ->get(['id', 'reference', 'bulk_invoice_id'])->keyBy('id');

        $out = [];
        foreach ($trips as $leg) {
            $bookingId = (int) $leg->legable_id;
            $rows = [];
            foreach ($byBooking[$bookingId] ?? [] as $invoiceId) {
                $invoice = $invoices->get($invoiceId);
                if ($invoice === null) {
                    continue;
                }
                $rows[] = [
                    'reference' => (string) ($invoice->reference ?? ''),
                    'bulk' => $invoice->bulkInvoice?->reference,
                    'listed' => in_array($invoiceId, $listed, true),
                ];
            }
            $out[] = [
                'trip' => (string) ($leg->reference ?? $term),
                'booking_id' => $bookingId,
                'booking' => (string) ($bookings[$bookingId] ?? ''),
                'cancelled' => $leg->status === LimoLeg::STATUS_CANCELLED,
                'invoices' => $rows,
            ];
        }

        return $out;
    }
}

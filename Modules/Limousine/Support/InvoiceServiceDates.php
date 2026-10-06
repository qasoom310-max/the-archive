<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoQuotation;

/**
 * When the work an invoice bills for was done: the date of its first trip.
 *
 * The office reads invoices by the month the trips ran, not the day the bill
 * was printed — an invoice issued on 1 September for August's trips belongs to
 * August. Kept on the invoice (`limo_invoices.service_date`) because an old
 * invoice's trips are only named in its notes, which no query can join on.
 * A bill with no trip (a late fee, a quote not yet dispatched) has none, and
 * is read by its issue date instead.
 */
final class InvoiceServiceDates
{
    /**
     * Recompute and store. All invoices when $invoiceIds is null.
     *
     * @param  list<int>|null  $invoiceIds
     */
    public static function sync(?array $invoiceIds = null): int
    {
        if (! Schema::hasColumn('limo_invoices', 'service_date') || $invoiceIds === []) {
            return 0;
        }

        $invoices = DB::table('limo_invoices')
            ->when($invoiceIds !== null, static fn ($q) => $q->whereIn('id', $invoiceIds))
            ->get(['id', 'customer_id', 'booking_id', 'quotation_id', 'notes', 'service_date']);

        $notesIds = [];
        foreach ($invoices as $invoice) {
            if ($invoice->booking_id === null && $invoice->quotation_id === null) {
                $notesIds[$invoice->id] = LegacyInvoiceBookings::ids(is_string($invoice->notes) ? $invoice->notes : null);
            }
        }

        $bookingIds = $invoices->pluck('booking_id')->filter()
            ->merge(collect($notesIds)->flatten())->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $quotationIds = $invoices->pluck('quotation_id')->filter()->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();

        $bookingFirst = self::firstTrips((new LimoBooking())->getMorphClass(), $bookingIds);
        $quotationFirst = self::firstTrips((new LimoQuotation())->getMorphClass(), $quotationIds);
        $bookingCustomer = [];
        foreach (array_chunk($bookingIds, 500) as $chunk) {
            $bookingCustomer += DB::table('limo_bookings')->whereIn('id', $chunk)->pluck('customer_id', 'id')->all();
        }

        $changed = 0;
        foreach ($invoices as $invoice) {
            if ($invoice->booking_id !== null) {
                $date = $bookingFirst[(int) $invoice->booking_id] ?? null;
            } elseif ($invoice->quotation_id !== null) {
                $date = $quotationFirst[(int) $invoice->quotation_id] ?? null;
            } else {
                $dates = [];
                foreach ($notesIds[$invoice->id] ?? [] as $id) {
                    if (isset($bookingFirst[$id]) && (int) ($bookingCustomer[$id] ?? 0) === (int) $invoice->customer_id) {
                        $dates[] = $bookingFirst[$id];
                    }
                }
                $date = $dates === [] ? null : min($dates);
            }

            $current = is_string($invoice->service_date) ? substr($invoice->service_date, 0, 10) : null;
            if ($current !== $date) {
                DB::table('limo_invoices')->where('id', $invoice->id)->update(['service_date' => $date]);
                $changed++;
            }
        }

        return $changed;
    }

    /** Re-read the invoices billing this booking or quotation (its trips changed). */
    public static function syncForParent(string $legableType, int $legableId): void
    {
        $column = match ($legableType) {
            (new LimoBooking())->getMorphClass() => 'booking_id',
            (new LimoQuotation())->getMorphClass() => 'quotation_id',
            default => null,
        };
        if ($column === null || ! Schema::hasColumn('limo_invoices', 'service_date')) {
            return;
        }

        $ids = LimoInvoice::query()->where($column, $legableId)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        // An old-system invoice names its bookings only in its notes.
        if ($column === 'booking_id') {
            $ids = array_merge($ids, LimoInvoice::query()
                ->whereNull('booking_id')->whereNull('quotation_id')
                ->where('notes', 'like', '%Bookings:%' . $legableId . '%')
                ->get(['id', 'notes'])
                ->filter(static fn (LimoInvoice $i): bool => in_array($legableId, LegacyInvoiceBookings::ids($i->notes), true))
                ->map(static fn (LimoInvoice $i): int => (int) $i->id)
                ->values()->all());
        }

        self::sync($ids);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> parent id → Y-m-d of its first trip
     */
    private static function firstTrips(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('limo_legs')
                ->where('legable_type', $type)
                ->whereIn('legable_id', $chunk)
                ->whereNotNull('start_at')
                ->groupBy('legable_id')
                ->selectRaw('legable_id, MIN(start_at) as first_at')
                ->get()
                ->each(static function (object $row) use (&$out): void {
                    $out[(int) $row->legable_id] = substr((string) $row->first_at, 0, 10);
                });
        }

        return $out;
    }
}

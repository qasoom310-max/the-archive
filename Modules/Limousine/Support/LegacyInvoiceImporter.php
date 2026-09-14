<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use RuntimeException;
use Throwable;

/**
 * Brings invoices over from the previous limousine system's own invoices list
 * export (Sl No. / Invoice # / Invoice Date / Customer Name / LPO # / Booking
 * Ref / Amount (BHD) / Added By / Actions).
 *
 * Keeps the old system's invoice number AS the invoice's id — so "Invoice
 * #1327" is INV/01327 here too, exactly as the 2026-09 Wanaan migration
 * stored them (see {@see \Modules\Limousine\Services\LimoInvoicePdf}'s
 * legacy multi-booking recovery, which this importer feeds) — so a number
 * already on file is recognised and skipped, and this can be re-run against
 * a fresher export to pick up only what's new.
 *
 * "Booking Ref" is a comma-separated list because the old system let one
 * invoice bill several trips at once. **A single booking already on file**
 * links `booking_id` directly, the normal shape every screen already
 * understands. **Anything else — several bookings, or the one booking not
 * (yet) on file** — is recorded the SAME way the original one-off migration
 * did: `booking_id`/`quotation_id` left null and the full list preserved as
 * `notes` ("Invoice #1327 | Bookings: 15119, 15124, …"), which
 * `LimoInvoicePdf::legacyMultiBookingLines()` already knows how to read back
 * into printable journey rows — including recovering a single not-yet-
 * imported booking once it later arrives.
 *
 * When a single booking IS linked, any receipt already recorded against that
 * booking with no invoice of its own (from an earlier, invoice-less receipts
 * import) is claimed by this invoice and the invoice's paid status is
 * recomputed — otherwise money already on file would sit invisible against a
 * bill that did not exist yet at the time the receipt was imported. This is
 * deliberately NOT attempted for the multi-booking case: a receipt could
 * belong to one specific invoice among several the source data raised for
 * overlapping bookings, and guessing would risk stealing it from the right
 * one.
 */
final class LegacyInvoiceImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'invoice #' => 'number',
        'invoice date' => 'date',
        'customer name' => 'customer',
        'booking ref' => 'booking_ref',
        'amount (bhd)' => 'amount',
    ];

    /** @var array<string, LimoCustomer> lower-case name → customer */
    private array $byName = [];

    /**
     * @return array{imported: int, skipped: int, lines: list<string>}
     */
    public function import(string $path, bool $pretend = false): array
    {
        $rows = $this->readRows($path);
        $this->indexCustomers();

        $result = ['imported' => 0, 'skipped' => 0, 'lines' => []];

        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                $result['lines'][] = $this->importRow($row, $result);
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // A dry run takes the exact same path, then throws every write away.
        $pretend ? DB::rollBack() : DB::commit();

        return $result;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array{imported: int, skipped: int, lines: list<string>}  $result
     */
    private function importRow(array $row, array &$result): string
    {
        $number = (int) preg_replace('/\D/', '', $row['number'] ?? '');
        $customerName = $this->clean($row['customer'] ?? '') ?? '';
        $amount = $this->money($row['amount'] ?? '');
        $date = $this->parseDate($row['date'] ?? '');
        $bookingIds = $this->parseBookingIds($row['booking_ref'] ?? '');

        $label = sprintf('INV/%05d %s (%s)', $number, number_format($amount, 3), $customerName !== '' ? $customerName : '?');

        if ($number <= 0 || $customerName === '') {
            $result['skipped']++;

            return "SKIP    {$label} — missing invoice number or customer";
        }

        $existing = LimoInvoice::query()->find($number);
        if ($existing !== null) {
            $result['skipped']++;

            return "EXISTS  {$label} — already on file (total ".number_format((float) $existing->total, 3).')';
        }

        $booking = count($bookingIds) === 1 ? LimoBooking::query()->find($bookingIds[0]) : null;

        $customer = null;
        $how = null;
        if ($booking !== null) {
            $customer = $booking->customer;
            if ($customer !== null) {
                $how = 'booking '.($booking->reference ?? ('BK/'.$bookingIds[0]));
            }
        }
        if ($customer === null) {
            [$customer, $how] = $this->resolveCustomer($customerName);
        }

        $invoice = new LimoInvoice;
        $invoice->forceFill([
            'id' => $number,
            'customer_id' => $customer->id,
            'booking_id' => $booking?->id,
            'issue_date' => $date,
            'due_date' => $date?->copy()->addWeek(),
            'subtotal' => $amount,
            'discount' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'status' => LimoInvoice::STATUS_UNPAID,
            'notes' => $booking !== null ? null : $this->legacyBookingsNote($number, $bookingIds),
        ]);
        $invoice->created_at = $date;
        $invoice->save();

        $claimed = $booking !== null ? $this->claimOrphanReceipts($booking->id, $invoice) : 0;

        $result['imported']++;

        $suffix = match (true) {
            $booking !== null => " — {$how}".($claimed > 0 ? ", claimed {$claimed} existing receipt(s)" : ''),
            count($bookingIds) > 1 => ' — combined invoice, bookings '.implode(', ', $bookingIds).' ('.count($bookingIds).')',
            count($bookingIds) === 1 => " — booking {$bookingIds[0]} not on file, recoverable once imported",
            default => ' — no booking reference',
        };

        return "NEW     {$label}{$suffix}";
    }

    /**
     * Claim standalone receipts (no invoice of their own) already on file
     * against this booking, then recompute the invoice's paid status from
     * them. Handles a receipt imported before its invoice existed.
     */
    private function claimOrphanReceipts(int $bookingId, LimoInvoice $invoice): int
    {
        $orphans = LimoReceipt::query()
            ->where('booking_id', $bookingId)
            ->whereNull('invoice_id')
            ->get();

        if ($orphans->isEmpty()) {
            return 0;
        }

        foreach ($orphans as $orphan) {
            $orphan->invoice_id = $invoice->id;
            $orphan->saveQuietly();
        }

        $invoice->refresh()->recomputePaid();

        return $orphans->count();
    }

    /**
     * "Invoice #1327 | Bookings: 15119, 15124, 15131" — the exact shape the
     * original migration used, which {@see \Modules\Limousine\Services\LimoInvoicePdf}
     * already parses back out. Used for a genuinely combined invoice AND for
     * a single booking not yet on file (so the same recovery picks it up
     * later without this invoice needing to be touched again).
     *
     * @param  list<int>  $bookingIds
     */
    private function legacyBookingsNote(int $invoiceNumber, array $bookingIds): ?string
    {
        if ($bookingIds === []) {
            return null;
        }

        return sprintf('Invoice #%d | Bookings: %s', $invoiceNumber, implode(', ', $bookingIds));
    }

    /**
     * @return list<int>
     */
    private function parseBookingIds(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $ids = array_map(static fn (string $piece): int => (int) trim($piece), explode(',', $raw));
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        return $ids;
    }

    /**
     * @return list<array<string, string>>
     */
    private function readRows(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return [];
        }

        $cols = [];
        foreach ($header as $i => $name) {
            $key = self::HEADER_MAP[strtolower(trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $name)))] ?? null;
            if ($key !== null && ! isset($cols[$key])) {
                $cols[$key] = $i;
            }
        }

        foreach (['number', 'customer', 'amount', 'booking_ref'] as $required) {
            if (! isset($cols[$required])) {
                fclose($handle);

                throw new RuntimeException("The file has no [{$required}] column.");
            }
        }

        $rows = [];
        while (($raw = fgetcsv($handle)) !== false) {
            if ($raw === [null]) {
                continue;
            }

            $row = [];
            foreach ($cols as $key => $i) {
                $row[$key] = trim((string) ($raw[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function indexCustomers(): void
    {
        $this->byName = [];

        LimoCustomer::query()->orderBy('id')->each(function (LimoCustomer $customer): void {
            $this->byName[strtolower(trim((string) $customer->name))] ??= $customer;
        });
    }

    /**
     * @return array{0: LimoCustomer, 1: string}
     */
    private function resolveCustomer(string $name): array
    {
        $existing = $this->byName[strtolower($name)] ?? null;
        if ($existing !== null) {
            return [$existing, 'matched by name'];
        }

        $customer = LimoCustomer::query()->create([
            'name' => $name,
            'type' => preg_match('/\b(w\.?l\.?l|company|co\.|travel|trad(e|ing)|group|hotel|llc|b\.?s\.?c|est)\b/i', $name) === 1
                ? LimoCustomer::TYPE_COMPANY
                : 'individual',
            'active' => true,
        ]);
        $this->byName[strtolower($name)] = $customer;

        return [$customer, 'new customer'];
    }

    private function money(string $value): float
    {
        return round((float) str_replace(',', '', $value), 3);
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d-M-Y', $value)?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function clean(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value !== '' ? $value : null;
    }
}

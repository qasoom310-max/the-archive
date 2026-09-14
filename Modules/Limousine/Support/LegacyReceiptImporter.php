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
 * Brings receipts over from the previous limousine system's own receipts list
 * export (Sl No. / RCPT No. / Date / Booking # / Customer / Amount / Pay type
 * / Comments / Actions) — a different shape from both this screen's own
 * export ({@see ReceiptImporter}) and the old system's Invoice column, so it
 * needs its own reader.
 *
 * Unlike {@see ReceiptImporter}, a row here links by BOOKING NUMBER, not an
 * invoice reference — the old system never printed which invoice a receipt
 * settled, only which trip. The old RCPT No. ("L-RCPT12968") is kept VERBATIM
 * in `reference` (never reformatted into the app's own "RCP/00042" shape,
 * which is auto-generated off the row's own id and could otherwise collide
 * with an unrelated receipt once the id range passes the old number) — so a
 * number already on file is recognised and skipped on every re-run, and this
 * command can be re-run periodically against a fresh export to pick up only
 * what is new.
 *
 * A booking already on file (matched by id, the same convention
 * {@see LegacyBookingImporter} used) links the receipt to it and to that
 * booking's OWN invoice if one already exists — never a new one: a booking
 * folded into a combined invoice covering several trips has no invoice of its
 * own to find, and minting a solo one here would misstate the accounts rather
 * than fix them, so a receipt like that is recorded against the booking alone
 * (exactly what {@see LimoReceipt::booking()} exists for). A booking number
 * not (yet) on file still records the receipt, standalone, against the
 * customer named on the row.
 *
 * Historical money has nothing left to check against a bank statement, so —
 * same as the booking import backfill — it lands already confirmed rather
 * than sitting in the accountant's queue for years-old trips.
 */
final class LegacyReceiptImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'rcpt no.' => 'reference',
        'date' => 'date',
        'booking #' => 'booking',
        'customer' => 'customer',
        'amount' => 'amount',
        'pay type' => 'pay_type',
        'comments' => 'comments',
    ];

    /**
     * @return array{imported: int, skipped: int, lines: list<string>}
     */
    public function import(string $path, bool $pretend = false): array
    {
        $rows = $this->readRows($path);

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
        $reference = $this->clean($row['reference'] ?? '') ?? '';
        $customerName = $this->clean($row['customer'] ?? '') ?? '';
        $amount = $this->money($row['amount'] ?? '');
        $date = $this->parseDate($row['date'] ?? '');
        $bookingNumber = (int) preg_replace('/\D/', '', $row['booking'] ?? '');

        $label = sprintf('%s %s (%s)', $reference !== '' ? $reference : '?', number_format($amount, 3), $customerName !== '' ? $customerName : '?');

        if ($reference === '' || $customerName === '' || $amount <= 0.0) {
            $result['skipped']++;

            return "SKIP    {$label} — missing receipt number, customer or amount";
        }

        if (LimoReceipt::query()->where('reference', $reference)->exists()) {
            $result['skipped']++;

            return "EXISTS  {$label} — {$reference} is already on file";
        }

        $booking = $bookingNumber > 0 ? LimoBooking::query()->with('customer:id,name')->find($bookingNumber) : null;
        $invoice = $booking !== null ? LimoInvoice::query()->where('booking_id', $booking->id)->first() : null;

        $customer = null;
        $how = null;
        if ($booking !== null) {
            $customer = $booking->customer;
            if ($customer !== null) {
                $how = 'booking '.($booking->reference ?? ('BK/'.$bookingNumber));
            }
        }
        if ($customer === null) {
            [$customer, $how] = $this->resolveCustomer($customerName);
        }

        [$method, $payTypeNote] = $this->method($row['pay_type'] ?? '');

        $receipt = new LimoReceipt;
        $receipt->forceFill([
            'reference' => $reference,
            'booking_id' => $booking?->id,
            'invoice_id' => $invoice?->id,
            'customer_id' => $customer->id,
            'date' => $date,
            'amount' => $amount,
            'balance_after' => $invoice !== null
                ? round(max(0.0, $invoice->total - $invoice->amount_paid - $amount), 3)
                : null,
            'method' => $method,
            'auto' => false,
            'notes' => $this->notes($bookingNumber, $booking, $row, $payTypeNote),
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => (string) __('Import (previous system)'),
        ]);
        $receipt->save();

        $result['imported']++;

        $note = $bookingNumber > 0 && $booking === null ? ' — booking #'.$bookingNumber.' not on file, standalone receipt' : " — {$how}";

        return "NEW     {$label}{$note}";
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

        foreach (['reference', 'customer', 'amount'] as $required) {
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

    /**
     * Name first (it is what the old system printed), else a new customer —
     * there is no phone column on this export to match by.
     *
     * @return array{0: LimoCustomer, 1: string}
     */
    private function resolveCustomer(string $name): array
    {
        $existing = LimoCustomer::query()->whereRaw('lower(trim(name)) = ?', [strtolower($name)])->first();
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

        return [$customer, 'new customer'];
    }

    /**
     * The old system's free-text "Pay type" onto this app's fixed method set.
     * Cheque and Advance describe something other than a plain method (a
     * cheque isn't quite a bank transfer; Advance is a timing, not a method
     * at all), so both — and anything unrecognised — are flagged in a note
     * rather than silently folded away.
     *
     * @return array{0: string, 1: string|null}
     */
    private function method(string $raw): array
    {
        $value = trim($raw);

        return match (strtolower($value)) {
            '', 'cash' => ['cash', null],
            'benefitpay', 'benefit' => ['benefit', null],
            'credit card', 'card' => ['card', null],
            'online' => ['card', null],
            'cheque', 'check' => ['transfer', 'Pay type: Cheque'],
            'advance' => ['cash', 'Pay type: Advance'],
            default => ['cash', "Pay type: {$value}"],
        };
    }

    /**
     * @param  array<string, string>  $row
     */
    private function notes(int $bookingNumber, ?LimoBooking $booking, array $row, ?string $payTypeNote): string
    {
        $parts = [(string) __('Imported from previous system.')];

        if ($bookingNumber > 0) {
            $parts[] = $booking !== null
                ? 'Booking '.($booking->reference ?? ('BK/'.$bookingNumber))
                : "Booking #{$bookingNumber} (not on file)";
        }

        if ($payTypeNote !== null) {
            $parts[] = $payTypeNote;
        }

        $comments = $this->clean($row['comments'] ?? '');
        if ($comments !== null) {
            $parts[] = $comments;
        }

        return implode(' | ', $parts);
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

<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoReceipt;

/**
 * A complete, lossless copy of one booking, for the "Live entry data" backup.
 *
 * The queue's printed columns are a summary: they drop the comments, who
 * entered the trip, when it was booked, the real trip and receipt numbers,
 * and how a round trip's legs belong together. A reimport of the summary
 * alone therefore loses all of that. This carries the stored row of the
 * booking, every one of its legs, its customer, its invoices and its
 * receipts exactly as the database holds them, so {@see restore()} can put
 * the booking back as it was.
 *
 * Raw attributes are used, never toArray(): toArray() serialises dates to
 * UTC, and writing that back would shift every time by the timezone offset.
 *
 * Rows keep their original ids where those are still free, so anything
 * else pointing at the booking (expenses, payment links, coupon use) stays
 * attached. Where an id has since been taken, the row gets a new one and
 * the references to it are remapped.
 */
final class BookingSnapshot
{
    public const VERSION = 1;

    /** Spreadsheet heading of the column that carries the snapshot. */
    public const HEADING = 'Record data (do not edit)';

    /**
     * @return array<string, mixed>
     */
    public function capture(LimoBooking $booking): array
    {
        $legs = LimoLeg::query()
            ->whereMorphedTo('legable', $booking)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $receipts = LimoReceipt::query()->where('booking_id', $booking->id)->orderBy('id')->get();

        // The booking's own invoice, plus any (e.g. a combined bill) that one
        // of its receipts was paid against.
        $invoiceIds = $receipts->pluck('invoice_id')->filter()->all();
        $invoices = LimoInvoice::query()
            ->where('booking_id', $booking->id)
            ->when($invoiceIds !== [], fn ($q) => $q->orWhereIn('id', $invoiceIds))
            ->orderBy('id')
            ->get();

        $customer = $booking->customer_id !== null
            ? LimoCustomer::query()->find($booking->customer_id)
            : null;

        return [
            'v' => self::VERSION,
            'booking' => $booking->getAttributes(),
            'legs' => $legs->map(fn (LimoLeg $leg): array => $leg->getAttributes())->all(),
            'customer' => $customer?->getAttributes(),
            'invoices' => $invoices->map(fn (LimoInvoice $i): array => $i->getAttributes())->all(),
            'receipts' => $receipts->map(fn (LimoReceipt $r): array => $r->getAttributes())->all(),
        ];
    }

    public function encode(LimoBooking $booking): string
    {
        return (string) json_encode($this->capture($booking), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>|null  null when the cell is not a snapshot
     */
    public function decode(string $cell): ?array
    {
        $cell = trim($cell);
        if ($cell === '' || $cell[0] !== '{') {
            return null;
        }

        $data = json_decode($cell, true);
        if (! is_array($data) || ($data['v'] ?? null) !== self::VERSION || ! is_array($data['booking'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Put a captured booking back. A booking whose reference is already on
     * file is left alone, so importing the same backup twice changes nothing.
     *
     * @param  array<string, mixed>  $data
     * @return bool  true when the booking was restored, false when it already existed
     */
    public function restore(array $data): bool
    {
        /** @var array<string, mixed> $bookingRow */
        $bookingRow = $data['booking'];
        $reference = (string) ($bookingRow['reference'] ?? '');

        if ($reference !== '' && LimoBooking::query()->where('reference', $reference)->exists()) {
            return false;
        }

        return DB::transaction(function () use ($data, $bookingRow): bool {
            $oldBookingId = (int) ($bookingRow['id'] ?? 0);

            /** @var array<string, mixed>|null $customerRow */
            $customerRow = is_array($data['customer'] ?? null) ? $data['customer'] : null;
            $customerId = $this->resolveCustomer($customerRow, $bookingRow['customer_id'] ?? null);

            $bookingRow['customer_id'] = $customerId;
            /** @var LimoBooking $booking */
            $booking = $this->insert(new LimoBooking(), $bookingRow);
            $bookingId = (int) $booking->id;

            /** @var list<array<string, mixed>> $legs */
            $legs = is_array($data['legs'] ?? null) ? $data['legs'] : [];
            foreach ($legs as $leg) {
                $leg['legable_type'] = $booking->getMorphClass();
                $leg['legable_id'] = $bookingId;
                $this->insert(new LimoLeg(), $leg);
            }

            /** @var array<int, int> $invoiceMap old invoice id → id now on file */
            $invoiceMap = [];
            /** @var list<array<string, mixed>> $invoices */
            $invoices = is_array($data['invoices'] ?? null) ? $data['invoices'] : [];
            foreach ($invoices as $invoice) {
                $oldId = (int) ($invoice['id'] ?? 0);
                // Reuse an invoice already on file under this number only when
                // it is the same customer's bill (e.g. a combined invoice two
                // bookings share). The old system reused numbers the ERP had
                // issued, so a matching number alone can be a different bill,
                // and this booking's receipts must never land on it.
                $existing = LimoInvoice::query()
                    ->where('reference', (string) ($invoice['reference'] ?? ''))
                    ->where('customer_id', $customerId)
                    ->value('id');
                if ($existing !== null) {
                    $invoiceMap[$oldId] = (int) $existing;

                    continue;
                }

                if ((int) ($invoice['booking_id'] ?? 0) === $oldBookingId) {
                    $invoice['booking_id'] = $bookingId;
                }
                if ((int) ($invoice['customer_id'] ?? 0) === (int) ($customerRow['id'] ?? 0)) {
                    $invoice['customer_id'] = $customerId;
                }
                $invoiceMap[$oldId] = (int) $this->insert(new LimoInvoice(), $invoice)->getKey();
            }

            /** @var list<array<string, mixed>> $receipts */
            $receipts = is_array($data['receipts'] ?? null) ? $data['receipts'] : [];
            foreach ($receipts as $receipt) {
                if (LimoReceipt::query()->where('reference', (string) ($receipt['reference'] ?? ''))->exists()) {
                    continue;
                }

                $receipt['booking_id'] = $bookingId;
                $receipt['customer_id'] = $customerId;
                $oldInvoice = (int) ($receipt['invoice_id'] ?? 0);
                $receipt['invoice_id'] = $invoiceMap[$oldInvoice] ?? null;
                $this->insert(new LimoReceipt(), $receipt);
            }

            return true;
        });
    }

    /**
     * The customer the booking belonged to: the same record when it is still
     * on file, else one with the same phone or name, else recreated.
     *
     * @param  array<string, mixed>|null  $row
     */
    private function resolveCustomer(?array $row, mixed $oldId): ?int
    {
        if ($row === null) {
            return null;
        }

        $name = trim((string) ($row['name'] ?? ''));

        $same = LimoCustomer::query()->find((int) $oldId);
        if ($same !== null && trim((string) $same->name) === $name) {
            return (int) $same->id;
        }

        $digits = preg_replace('/\D+/', '', (string) ($row['phone'] ?? '')) ?? '';
        if (strlen($digits) >= 7) {
            $ending = substr($digits, -8);
            $byPhone = LimoCustomer::query()->where('phone', 'like', '%'.$ending)->value('id');
            if ($byPhone !== null) {
                return (int) $byPhone;
            }
        }

        if ($name !== '') {
            $byName = LimoCustomer::query()->where('name', $name)->value('id');
            if ($byName !== null) {
                return (int) $byName;
            }
        }

        return (int) $this->insert(new LimoCustomer(), $row)->getKey();
    }

    /**
     * Insert a stored row as-is: original id when still free, and only the
     * columns the table has today (a later migration may have dropped one).
     * Saved quietly, so model hooks can't renumber or re-price what the
     * backup already records.
     *
     * @param  array<string, mixed>  $row
     */
    private function insert(Model $model, array $row): Model
    {
        $columns = array_flip(Schema::getColumnListing($model->getTable()));
        $row = array_intersect_key($row, $columns);

        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || $model->newQuery()->whereKey($id)->exists()) {
            unset($row['id']);
        }

        $model->setRawAttributes($row);
        $model->saveQuietly();

        return $model;
    }
}

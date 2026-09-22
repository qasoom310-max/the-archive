<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoReceipt;
use Throwable;

/**
 * Imports trips from a CSV (an export from a previous system) into the
 * booking queue.
 *
 * The expected columns are deliberately the SAME shape the queue's own
 * export already prints (Reference, From date, To date, Type, Customer,
 * Amount, Received, Pickup, Drop off, Vehicle, Driver, Company reference,
 * Pax name, Status, Payment) — a sheet pulled off the old system's own
 * "queue" screen should need no re-typing to come back in here.
 *
 * A row becomes a booking with one leg, and — landing as SETTLED HISTORY,
 * never through the live pricing/payment flow a booking taken today goes
 * through — an invoice and (when money was received) a receipt to match,
 * mirroring the shape the 2026-09-01 backfill migration gave every booking
 * already on file when the invoice chain was introduced. A booking created
 * today issues its invoice through syncInvoice(); an imported one gets its
 * invoice built directly at the figure the old system already recorded,
 * because that invoice must never re-price itself off today's rules.
 */
final class BookingImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference', 'booking #' => 'reference', 'booking no' => 'reference',
        'from date' => 'from_date', 'pickup date' => 'from_date', 'pick-up' => 'from_date', 'date' => 'from_date',
        'to date' => 'to_date',
        'type' => 'type', 'service' => 'type',
        'customer' => 'customer',
        'amount' => 'amount', 'fare' => 'amount', 'total' => 'amount',
        'received' => 'received', 'advance' => 'received', 'paid' => 'received',
        'pickup' => 'pickup', 'from' => 'pickup', 'from location' => 'pickup',
        'drop off' => 'dropoff', 'dropoff' => 'dropoff', 'to' => 'dropoff', 'to location' => 'dropoff',
        'vehicle' => 'vehicle', 'car type' => 'vehicle', 'car' => 'vehicle',
        'driver' => 'driver',
        'company reference' => 'company_reference', 'company ref' => 'company_reference', 'client reference' => 'company_reference',
        'pax name' => 'pax_name', 'passenger' => 'pax_name',
        'status' => 'status',
        'payment' => 'payment',
    ];

    /**
     * @return array{imported: int, skipped: int}
     */
    public function import(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['imported' => 0, 'skipped' => 0];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);

        $cols = [];
        foreach ($header as $i => $name) {
            $role = self::HEADER_MAP[strtolower(trim((string) $name))] ?? null;
            if ($role !== null) {
                $cols[$role] = $i;
            }
        }

        if (! isset($cols['customer']) || ! isset($cols['amount'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        // Our own "Live entry data" backup carries the whole stored booking
        // in its last column; when it's there, restore from that instead of
        // rebuilding a thinner booking out of the printed columns.
        $recordCol = null;
        foreach ($header as $i => $name) {
            if (strcasecmp(trim((string) $name), BookingSnapshot::HEADING) === 0) {
                $recordCol = $i;
            }
        }
        $snapshots = app(BookingSnapshot::class);
        /** @var array<string, bool> $restoredNow booking reference → restored by this run */
        $restoredNow = [];

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $snapshot = $recordCol !== null ? $snapshots->decode((string) ($row[$recordCol] ?? '')) : null;
            if ($snapshot !== null) {
                // One row per trip, each carrying its whole booking: the first
                // row restores it, the rest of that booking's rows count along.
                $key = (string) ($snapshot['booking']['reference'] ?? '').'#'.(string) ($snapshot['booking']['id'] ?? '');
                if (! array_key_exists($key, $restoredNow)) {
                    $restoredNow[$key] = $snapshots->restore($snapshot);
                }
                $restoredNow[$key] ? $imported++ : $skipped++;

                continue;
            }

            $customerName = trim((string) ($row[$cols['customer']] ?? ''));
            $amountRaw = trim((string) ($row[$cols['amount']] ?? ''));

            if ($customerName === '' || $amountRaw === '') {
                continue;
            }

            $fare = round((float) str_replace(',', '', $amountRaw), 3);
            $pickupAt = $this->parseDate((string) ($row[$cols['from_date']] ?? ''));

            // Dedup: the same customer, the same pickup minute, the same fare
            // is almost certainly the same trip re-appearing in a second export
            // — no reference column survives from most old systems to key on.
            if ($pickupAt !== null && $this->alreadyImported($customerName, $pickupAt, $fare)) {
                $skipped++;

                continue;
            }

            $customer = $this->resolveCustomer($customerName);
            $received = round((float) str_replace(',', '', (string) ($row[$cols['received']] ?? '0')), 3);
            $received = min($received, $fare);

            // LimoBooking and LimoLeg share the same status vocabulary
            // (queue/confirmed/active/completed/cancelled), so one mapping
            // serves both the trip and its single leg.
            $status = $this->tripStatus(isset($cols['status']) ? (string) ($row[$cols['status']] ?? '') : '');

            $booking = LimoBooking::query()->create([
                'customer_id' => $customer->id,
                'pax_name' => isset($cols['pax_name']) ? $this->orNull((string) ($row[$cols['pax_name']] ?? '')) : $customerName,
                'company_reference' => isset($cols['company_reference']) ? $this->orNull((string) ($row[$cols['company_reference']] ?? '')) : null,
                'pickup_at' => $pickupAt,
                'car_type' => isset($cols['vehicle']) ? $this->orNull((string) ($row[$cols['vehicle']] ?? '')) : null,
                'driver_name' => isset($cols['driver']) ? $this->orNull((string) ($row[$cols['driver']] ?? '')) : null,
                'fare' => $fare,
                'amount' => $fare,
                'advance' => $received,
                'status' => $status,
                'payment_status' => $received >= $fare && $fare > 0 ? LimoBooking::PAYMENT_PAID : LimoBooking::PAYMENT_UNPAID,
                'notes' => __('Imported from previous system.'),
            ]);

            $booking->legs()->create([
                'sequence' => 0,
                'service_type' => $this->serviceType(isset($cols['type']) ? (string) ($row[$cols['type']] ?? '') : ''),
                'from_location' => isset($cols['pickup']) ? $this->orNull((string) ($row[$cols['pickup']] ?? '')) : null,
                'to_location' => isset($cols['dropoff']) ? $this->orNull((string) ($row[$cols['dropoff']] ?? '')) : null,
                'start_at' => $pickupAt,
                'days' => 1,
                'vehicle' => isset($cols['vehicle']) ? $this->orNull((string) ($row[$cols['vehicle']] ?? '')) : null,
                'driver' => isset($cols['driver']) ? $this->orNull((string) ($row[$cols['driver']] ?? '')) : null,
                'rate' => $fare,
                'rate_basis' => LimoLeg::BASIS_TRIP,
                'net_amount' => $fare,
                'status' => $status,
            ]);

            $this->backfillInvoiceAndReceipt($booking, $fare, $received, $pickupAt);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Settled history: dated to the trip, money already received matched on —
     * never syncInvoice()/followTotal(), which are for a job still being
     * priced today.
     */
    private function backfillInvoiceAndReceipt(LimoBooking $booking, float $fare, float $received, ?Carbon $pickupAt): void
    {
        $issueDate = $pickupAt ?? Carbon::now();

        $status = match (true) {
            $received <= 0.0005 => LimoInvoice::STATUS_UNPAID,
            $received + 0.0005 < $fare => LimoInvoice::STATUS_PARTIAL,
            default => LimoInvoice::STATUS_PAID,
        };

        $invoice = LimoInvoice::query()->create([
            'customer_id' => $booking->customer_id,
            'booking_id' => $booking->id,
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'subtotal' => $fare,
            'total' => $fare,
            'amount_paid' => $received,
            'status' => $status,
        ]);

        if ($received > 0.0005) {
            LimoReceipt::query()->create([
                'invoice_id' => $invoice->id,
                'booking_id' => $booking->id,
                'customer_id' => $booking->customer_id,
                'date' => $issueDate,
                'amount' => $received,
                'balance_after' => round(max(0.0, $fare - $received), 3),
                'method' => 'cash',
                'auto' => false,
                'notes' => __('Imported from previous system.'),
                // Historical money — nothing left to check against a bank
                // statement, so it starts confirmed rather than sitting in
                // the accountant's queue for years-old trips.
                'confirmed_at' => Carbon::now(),
                'confirmed_by' => __('Import (previous system)'),
            ]);
        }
    }

    private function alreadyImported(string $customerName, Carbon $pickupAt, float $fare): bool
    {
        return LimoBooking::query()
            ->whereHas('customer', fn ($c) => $c->where('name', $customerName))
            ->whereBetween('pickup_at', [$pickupAt->copy()->subMinute(), $pickupAt->copy()->addMinute()])
            ->whereBetween('fare', [$fare - 0.001, $fare + 0.001])
            ->exists();
    }

    private function resolveCustomer(string $name): LimoCustomer
    {
        $existing = LimoCustomer::query()->where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        return LimoCustomer::query()->create(['name' => $name, 'active' => true]);
    }

    private function tripStatus(string $value): string
    {
        return match (strtolower(trim($value))) {
            'queue', 'new' => LimoBooking::STATUS_QUEUE,
            'confirmed' => LimoBooking::STATUS_CONFIRMED,
            'active', 'ongoing' => LimoBooking::STATUS_ACTIVE,
            'cancelled', 'canceled' => LimoBooking::STATUS_CANCELLED,
            // Historical rows are, overwhelmingly, trips that already happened.
            default => LimoBooking::STATUS_COMPLETED,
        };
    }

    private function serviceType(string $value): string
    {
        return str_contains(strtolower($value), 'chauffeur') ? LimoLeg::TYPE_CHAUFFEUR : LimoLeg::TYPE_TRANSFER;
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function orNull(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Illuminate\Support\Carbon;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Throwable;

/**
 * Imports rental orders from a CSV (an export from a previous system) into
 * the orders list.
 *
 * The expected columns are deliberately the SAME shape the orders list's own
 * export already prints (Reference, Customer, Car, Pick-up, Return, Total,
 * Status, Payment) — a sheet pulled off this screen's own download should
 * need no re-typing to come back in here.
 *
 * Unlike Limousine's bookings, a rental order carries its own billing
 * figures directly (advance_amount / balance / payment_status) rather than
 * a separate invoice document that is auto-raised — {@see
 * \Modules\Rental\Models\RentalOrder::createInvoice()} is a manual, optional
 * step here, so importing an order needs nothing further to be a complete
 * historical record.
 */
final class OrderImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference',
        'customer' => 'customer',
        'car' => 'car', 'vehicle' => 'car',
        'pick-up' => 'pickup', 'pickup' => 'pickup', 'start date' => 'pickup', 'from' => 'pickup',
        'return' => 'return', 'end date' => 'return', 'to' => 'return',
        'total' => 'total', 'amount' => 'total',
        'received' => 'received', 'advance' => 'received', 'paid' => 'received',
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

        if (! isset($cols['customer']) || ! isset($cols['total'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $customerName = trim((string) ($row[$cols['customer']] ?? ''));
            $totalRaw = trim((string) ($row[$cols['total']] ?? ''));

            if ($customerName === '' || $totalRaw === '') {
                continue;
            }

            $total = round((float) str_replace(',', '', $totalRaw), 3);
            $pickup = $this->parseDate((string) ($row[$cols['pickup']] ?? ''));

            // Dedup: the same customer, the same pick-up date, the same total
            // is almost certainly the same order re-appearing in a second
            // export — no reference column survives from most old systems.
            if ($pickup !== null && $this->alreadyImported($customerName, $pickup, $total)) {
                $skipped++;

                continue;
            }

            $customer = $this->resolveCustomer($customerName);
            $vehicle = isset($cols['car']) ? $this->resolveVehicle((string) ($row[$cols['car']] ?? '')) : null;
            $return = isset($cols['return']) ? $this->parseDate((string) ($row[$cols['return']] ?? '')) : null;
            $received = round((float) str_replace(',', '', (string) ($row[$cols['received']] ?? '0')), 3);
            $received = min($received, $total);

            RentalOrder::query()->create([
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle?->id,
                'order_date' => $pickup ?? Carbon::now(),
                'start_date' => $pickup,
                'end_date' => $return,
                'rate_type' => 'daily',
                'subtotal' => $total,
                'total' => $total,
                'advance_amount' => $received,
                'balance' => round(max(0.0, $total - $received), 3),
                'state' => $this->orderState(isset($cols['status']) ? (string) ($row[$cols['status']] ?? '') : ''),
                'payment_status' => $this->paymentStatus($received, $total),
                'notes' => __('Imported from previous system.'),
            ]);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function alreadyImported(string $customerName, Carbon $pickup, float $total): bool
    {
        return RentalOrder::query()
            ->whereHas('customer', fn ($c) => $c->where('name', $customerName))
            ->whereDate('start_date', $pickup->toDateString())
            ->whereBetween('total', [$total - 0.001, $total + 0.001])
            ->exists();
    }

    private function resolveCustomer(string $name): RentalCustomer
    {
        $existing = RentalCustomer::query()->where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        return RentalCustomer::query()->create(['name' => $name, 'active' => true]);
    }

    /**
     * The fleet should already be on file (Vehicle has its own import) — an
     * unmatched name is left null rather than inventing a fleet entry.
     */
    private function resolveVehicle(string $label): ?Vehicle
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }

        return Vehicle::query()->where('name', 'like', "%{$label}%")->orWhere('plate_no', $label)->first();
    }

    private function orderState(string $value): string
    {
        return match (strtolower(trim($value))) {
            'draft', 'reservation' => RentalOrder::STATE_DRAFT,
            'active', 'ongoing' => RentalOrder::STATE_ACTIVE,
            'cancelled', 'canceled' => RentalOrder::STATE_CANCELLED,
            // Historical rows are, overwhelmingly, rentals that already closed.
            default => RentalOrder::STATE_CLOSED,
        };
    }

    private function paymentStatus(float $received, float $total): string
    {
        return match (true) {
            $total <= 0.0 || $received >= $total => RentalOrder::PAYMENT_PAID,
            $received > 0.0 => RentalOrder::PAYMENT_PARTIAL,
            default => RentalOrder::PAYMENT_UNPAID,
        };
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
}

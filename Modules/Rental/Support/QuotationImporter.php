<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Illuminate\Support\Carbon;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\Vehicle;
use Throwable;

/**
 * Imports quotations from a CSV (an export from a previous system) into the
 * quotations list.
 *
 * The expected columns match this screen's own export (Reference, Customer,
 * Car, Valid until, Total, Status). A quote is a price offered, not yet
 * billed, so there is no invoice to backfill here.
 */
final class QuotationImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference',
        'customer' => 'customer',
        'car' => 'car', 'vehicle' => 'car',
        'valid until' => 'valid_until', 'expiry' => 'valid_until',
        'total' => 'total', 'amount' => 'total', 'fare' => 'total',
        'status' => 'status',
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
            $validUntil = isset($cols['valid_until']) ? $this->parseDate((string) ($row[$cols['valid_until']] ?? '')) : null;

            if ($this->alreadyImported($customerName, $total, $validUntil)) {
                $skipped++;

                continue;
            }

            $customer = $this->resolveCustomer($customerName);
            $vehicle = isset($cols['car']) ? $this->resolveVehicle((string) ($row[$cols['car']] ?? '')) : null;

            RentalQuotation::query()->create([
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle?->id,
                'total' => $total,
                'subtotal' => $total,
                'valid_until' => $validUntil,
                'status' => $this->status(isset($cols['status']) ? (string) ($row[$cols['status']] ?? '') : ''),
                'notes' => __('Imported from previous system.'),
            ]);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function alreadyImported(string $customerName, float $total, ?Carbon $validUntil): bool
    {
        $query = RentalQuotation::query()
            ->whereHas('customer', fn ($c) => $c->where('name', $customerName))
            ->whereBetween('total', [$total - 0.001, $total + 0.001]);

        if ($validUntil !== null) {
            $query->whereDate('valid_until', $validUntil->toDateString());
        }

        return $query->exists();
    }

    private function resolveCustomer(string $name): RentalCustomer
    {
        $existing = RentalCustomer::query()->where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        return RentalCustomer::query()->create(['name' => $name, 'active' => true]);
    }

    /** The fleet should already be on file — an unmatched name is left null. */
    private function resolveVehicle(string $label): ?Vehicle
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }

        return Vehicle::query()->where('name', 'like', "%{$label}%")->orWhere('plate_no', $label)->first();
    }

    private function status(string $value): string
    {
        return match (strtolower(trim($value))) {
            'sent' => RentalQuotation::STATUS_SENT,
            'accepted' => RentalQuotation::STATUS_ACCEPTED,
            'converted' => RentalQuotation::STATUS_CONVERTED,
            'declined' => RentalQuotation::STATUS_DECLINED,
            default => RentalQuotation::STATUS_DRAFT,
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

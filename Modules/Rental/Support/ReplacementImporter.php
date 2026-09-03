<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Illuminate\Support\Carbon;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalReplacement;
use Modules\Rental\Models\Vehicle;
use Throwable;

/**
 * Imports car-replacement records from a CSV (an export from a previous
 * system) into the replacements list.
 *
 * The expected columns match this screen's own export (Reference, Customer,
 * Original car, Replacement car, Date, Status). A row lands as a plain
 * record of a swap that already happened — never through activate(), which
 * exists to physically move a vehicle's status and re-point a LIVE order
 * today, not to replay a swap from years ago. No order is linked
 * (order_id stays null); historical rows default to Closed.
 */
final class ReplacementImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference',
        'customer' => 'customer',
        'original car' => 'original_car', 'original' => 'original_car', 'original vehicle' => 'original_car',
        'replacement car' => 'replacement_car', 'replacement' => 'replacement_car', 'replacement vehicle' => 'replacement_car',
        'date' => 'date',
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

        if (! isset($cols['customer'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $customerName = trim((string) ($row[$cols['customer']] ?? ''));

            if ($customerName === '') {
                continue;
            }

            $date = isset($cols['date']) ? $this->parseDate((string) ($row[$cols['date']] ?? '')) : null;
            $originalLabel = isset($cols['original_car']) ? trim((string) ($row[$cols['original_car']] ?? '')) : '';
            $replacementLabel = isset($cols['replacement_car']) ? trim((string) ($row[$cols['replacement_car']] ?? '')) : '';

            if ($this->alreadyImported($customerName, $date, $originalLabel, $replacementLabel)) {
                $skipped++;

                continue;
            }

            $customer = $this->resolveCustomer($customerName);
            $original = $originalLabel !== '' ? $this->resolveVehicle($originalLabel) : null;
            $replacement = $replacementLabel !== '' ? $this->resolveVehicle($replacementLabel) : null;

            RentalReplacement::query()->create([
                'customer_id' => $customer->id,
                'original_vehicle_id' => $original?->id,
                'replacement_vehicle_id' => $replacement?->id,
                'date' => $date,
                'status' => $this->status(isset($cols['status']) ? (string) ($row[$cols['status']] ?? '') : ''),
                'notes' => __('Imported from previous system.'),
            ]);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function alreadyImported(string $customerName, ?Carbon $date, string $originalLabel, string $replacementLabel): bool
    {
        $query = RentalReplacement::query()
            ->whereHas('customer', fn ($c) => $c->where('name', $customerName));

        if ($date !== null) {
            $query->whereDate('date', $date->toDateString());
        }

        if ($originalLabel !== '') {
            $query->whereHas('originalVehicle', fn ($v) => $v->where('name', 'like', "%{$originalLabel}%"));
        }

        if ($replacementLabel !== '') {
            $query->whereHas('replacementVehicle', fn ($v) => $v->where('name', 'like', "%{$replacementLabel}%"));
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
        return Vehicle::query()->where('name', 'like', "%{$label}%")->orWhere('plate_no', $label)->first();
    }

    private function status(string $value): string
    {
        return match (strtolower(trim($value))) {
            'active' => RentalReplacement::STATUS_ACTIVE,
            // Historical rows are, overwhelmingly, swaps already resolved.
            default => RentalReplacement::STATUS_CLOSED,
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

<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Illuminate\Support\Carbon;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\Vehicle;
use Throwable;

/**
 * Imports vehicle maintenance records from a CSV (an export from a previous
 * system) into the maintenance list.
 *
 * The expected columns match this screen's own export (Reference, Car, Type,
 * Priority, Date, Cost, Status). A row lands directly at its recorded status
 * — never through the approve()/start()/complete() workflow, which exists for
 * a work order still moving through the shop, not one already finished years
 * ago. Historical rows default to Done, same reasoning Booking/Order import
 * used for a completed trip.
 */
final class MaintenanceImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference',
        'car' => 'car', 'vehicle' => 'car',
        'type' => 'type',
        'priority' => 'priority',
        'date' => 'date',
        'cost' => 'cost', 'amount' => 'cost',
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

        if (! isset($cols['car']) || ! isset($cols['cost'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $carLabel = trim((string) ($row[$cols['car']] ?? ''));
            $costRaw = trim((string) ($row[$cols['cost']] ?? ''));

            if ($carLabel === '' || $costRaw === '') {
                continue;
            }

            $cost = round((float) str_replace(',', '', $costRaw), 3);
            $vehicle = $this->resolveVehicle($carLabel);
            $date = isset($cols['date']) ? $this->parseDate((string) ($row[$cols['date']] ?? '')) : null;

            if ($this->alreadyImported($vehicle?->id, $carLabel, $cost, $date)) {
                $skipped++;

                continue;
            }

            RentalMaintenance::query()->create([
                'vehicle_id' => $vehicle?->id,
                'date' => $date,
                'type' => $this->type(isset($cols['type']) ? (string) ($row[$cols['type']] ?? '') : ''),
                'priority' => $this->priority(isset($cols['priority']) ? (string) ($row[$cols['priority']] ?? '') : ''),
                'description' => $vehicle === null ? $carLabel : null,
                'cost' => $cost,
                'status' => $this->status(isset($cols['status']) ? (string) ($row[$cols['status']] ?? '') : ''),
                'notes' => __('Imported from previous system.'),
            ]);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function alreadyImported(?int $vehicleId, string $carLabel, float $cost, ?Carbon $date): bool
    {
        $query = RentalMaintenance::query()->whereBetween('cost', [$cost - 0.001, $cost + 0.001]);

        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        } else {
            $query->whereNull('vehicle_id')->where('description', $carLabel);
        }

        if ($date !== null) {
            $query->whereDate('date', $date->toDateString());
        }

        return $query->exists();
    }

    /** The fleet should already be on file — an unmatched name is left null. */
    private function resolveVehicle(string $label): ?Vehicle
    {
        return Vehicle::query()->where('name', 'like', "%{$label}%")->orWhere('plate_no', $label)->first();
    }

    private function type(string $value): string
    {
        $value = strtolower(str_replace([' ', '-'], '_', trim($value)));

        return in_array($value, ['service', 'repair', 'oil_change', 'tyres', 'insurance', 'registration', 'accident', 'other'], true)
            ? $value
            : 'other';
    }

    private function priority(string $value): string
    {
        return match (strtolower(trim($value))) {
            'low' => RentalMaintenance::PRIORITY_LOW,
            'high' => RentalMaintenance::PRIORITY_HIGH,
            'critical' => RentalMaintenance::PRIORITY_CRITICAL,
            default => RentalMaintenance::PRIORITY_NORMAL,
        };
    }

    private function status(string $value): string
    {
        return match (strtolower(trim($value))) {
            'pending', 'pending approval' => RentalMaintenance::STATUS_PENDING,
            'approved' => RentalMaintenance::STATUS_APPROVED,
            'in progress', 'in_progress' => RentalMaintenance::STATUS_IN_PROGRESS,
            'declined' => RentalMaintenance::STATUS_DECLINED,
            'cancelled', 'canceled' => RentalMaintenance::STATUS_CANCELLED,
            // Historical rows are, overwhelmingly, work that already happened.
            default => RentalMaintenance::STATUS_DONE,
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

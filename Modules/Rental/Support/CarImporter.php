<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Modules\Rental\Models\Vehicle;

/**
 * Imports fleet cars from a CSV (an export from a previous system) into the
 * rental vehicles store. Used by both the upload controller and the
 * `rental:import-cars` command.
 *
 * Forgiving format: columns named like Name, Reg.No (Plate), Year, Color, Type,
 * Status are read (case-insensitive); other columns are ignored. The old "Type"
 * tier maps to a body category (Small→economy, Mid range→sedan, Luxury→luxury);
 * Status maps to the live state (Rented Out→rented, Maintenance→maintenance, else
 * available). Existing cars are matched by plate (a real plate, not 0/blank) and
 * skipped, so re-importing never creates duplicates.
 */
final class CarImporter
{
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
            $role = $this->columnRole(strtolower(trim((string) $name)));
            if ($role !== null) {
                $cols[$role] = $i;
            }
        }

        if (! isset($cols['name'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $existingPlates = Vehicle::query()->whereNotNull('plate_no')->where('plate_no', '!=', '')
            ->pluck('plate_no')->map(fn (string $v): string => $this->normalise($v))->flip();

        $imported = 0;
        $skipped = 0;
        $seen = [];

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string) ($row[$cols['name']] ?? ''));
            if ($name === '') {
                continue;
            }

            $plate = isset($cols['plate']) ? trim((string) ($row[$cols['plate']] ?? '')) : '';
            $plateKey = $this->normalise($plate);

            // Dedup on a real plate only — "0"/blank plates are kept (several cars share them).
            $hasRealPlate = $plate !== '' && $plate !== '0';
            if ($hasRealPlate && ($existingPlates->has($plateKey) || isset($seen[$plateKey]))) {
                $skipped++;

                continue;
            }

            $year = isset($cols['year']) ? (int) preg_replace('/\D/', '', (string) ($row[$cols['year']] ?? '')) : 0;

            Vehicle::query()->create([
                'name' => $name,
                'plate_no' => $plate !== '' ? $plate : null,
                'year' => $year > 0 ? $year : null,
                'color' => isset($cols['color']) ? ($this->clean((string) ($row[$cols['color']] ?? '')) ?: null) : null,
                'category' => $this->category(isset($cols['type']) ? (string) ($row[$cols['type']] ?? '') : ''),
                'status' => $this->status(isset($cols['status']) ? (string) ($row[$cols['status']] ?? '') : ''),
                'active' => true,
            ]);

            $imported++;
            if ($hasRealPlate) {
                $seen[$plateKey] = true;
            }
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function columnRole(string $key): ?string
    {
        return match (true) {
            in_array($key, ['name', 'car', 'model', 'vehicle'], true) => 'name',
            in_array($key, ['reg.no', 'reg no', 'regno', 'reg', 'plate', 'plate no', 'plate_no', 'plate number'], true) => 'plate',
            $key === 'year' => 'year',
            in_array($key, ['color', 'colour'], true) => 'color',
            in_array($key, ['type', 'category', 'class'], true) => 'type',
            $key === 'status' => 'status',
            default => null,
        };
    }

    private function category(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'small' => 'economy',
            'mid range', 'midrange', 'mid-range' => 'sedan',
            'luxury' => 'luxury',
            'suv' => 'suv',
            'van' => 'van',
            'bus' => 'bus',
            'economy' => 'economy',
            'sedan' => 'sedan',
            default => null,
        };
    }

    private function status(string $status): string
    {
        return match (strtolower(trim($status))) {
            'rented out', 'rented', 'out' => Vehicle::STATUS_RENTED,
            'maintenance', 'under maintenance' => Vehicle::STATUS_MAINTENANCE,
            'reserved' => Vehicle::STATUS_RESERVED,
            default => Vehicle::STATUS_AVAILABLE,
        };
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function normalise(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }
}

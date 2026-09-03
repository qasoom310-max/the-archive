<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Modules\Limousine\Models\LimoLocation;

/**
 * Imports pickup/dropoff locations from a CSV (an export from a previous
 * system) into the locations list.
 *
 * The expected columns match this screen's own export (Name, Area, Active).
 * A location is a plain reference row — no money, no workflow — so a row is
 * simply created, or skipped when a location of that name is already on file.
 */
final class LocationImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'name' => 'name',
        'area' => 'area',
        'notes' => 'notes',
        'active' => 'active',
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

        if (! isset($cols['name'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string) ($row[$cols['name']] ?? ''));

            if ($name === '') {
                continue;
            }

            if (LimoLocation::query()->where('name', $name)->exists()) {
                $skipped++;

                continue;
            }

            $activeRaw = isset($cols['active']) ? trim((string) ($row[$cols['active']] ?? '')) : '';

            LimoLocation::query()->create([
                'name' => $name,
                'area' => isset($cols['area']) ? $this->orNull((string) ($row[$cols['area']] ?? '')) : null,
                'notes' => isset($cols['notes']) ? $this->orNull((string) ($row[$cols['notes']] ?? '')) : null,
                'active' => $activeRaw === '' || $this->truthy($activeRaw),
            ]);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'yes', 'true', 'active', 'y'], true);
    }

    private function orNull(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}

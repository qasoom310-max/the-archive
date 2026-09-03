<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Illuminate\Support\Carbon;
use Modules\Rental\Models\Driver;
use Throwable;

/**
 * Imports drivers from a CSV (an export from a previous system) into the
 * shared driver store — the same people who drive for both Rent A Car and
 * Limousine, so one importer serves both apps' Import buttons.
 *
 * Forgiving format: columns named like Name, Phone, CPR, License No,
 * License Expiry, Nationality are read (case-insensitive); other columns are
 * ignored. Existing drivers are matched by CPR (then license number, then
 * phone) and skipped, so re-importing never creates duplicates.
 */
final class DriverImporter
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

        $existingCpr = Driver::query()->whereNotNull('cpr')->where('cpr', '!=', '')
            ->pluck('cpr')->map(fn (string $v): string => $this->normalise($v))->flip();
        $existingLicense = Driver::query()->whereNotNull('license_no')->where('license_no', '!=', '')
            ->pluck('license_no')->map(fn (string $v): string => $this->normalise($v))->flip();
        $existingPhones = Driver::query()->whereNotNull('phone')->where('phone', '!=', '')
            ->pluck('phone')->map(fn (string $v): string => $this->normalise($v))->flip();

        $imported = 0;
        $skipped = 0;
        $seenCpr = [];
        $seenLicense = [];
        $seenPhone = [];

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string) ($row[$cols['name']] ?? ''));
            if ($name === '') {
                continue;
            }

            $cprRaw = isset($cols['cpr']) ? trim((string) ($row[$cols['cpr']] ?? '')) : '';
            $licenseRaw = isset($cols['license']) ? trim((string) ($row[$cols['license']] ?? '')) : '';
            $phoneRaw = isset($cols['phone']) ? trim((string) ($row[$cols['phone']] ?? '')) : '';
            $nationality = isset($cols['nationality']) ? trim((string) ($row[$cols['nationality']] ?? '')) : '';

            $cprKey = $cprRaw !== '' ? $this->normalise($cprRaw) : '';
            $licenseKey = $licenseRaw !== '' ? $this->normalise($licenseRaw) : '';
            $phoneKey = $phoneRaw !== '' ? $this->normalise($phoneRaw) : '';

            $dup = ($cprKey !== '' && ($existingCpr->has($cprKey) || isset($seenCpr[$cprKey])))
                || ($cprKey === '' && $licenseKey !== '' && ($existingLicense->has($licenseKey) || isset($seenLicense[$licenseKey])))
                || ($cprKey === '' && $licenseKey === '' && $phoneKey !== '' && ($existingPhones->has($phoneKey) || isset($seenPhone[$phoneKey])));

            if ($dup) {
                $skipped++;

                continue;
            }

            Driver::query()->create([
                'name' => $name,
                'cpr' => $cprRaw !== '' ? $cprRaw : null,
                'license_no' => $licenseRaw !== '' ? $licenseRaw : null,
                'license_expiry' => isset($cols['expiry']) ? $this->parseDate((string) ($row[$cols['expiry']] ?? '')) : null,
                'phone' => $phoneRaw !== '' ? $phoneRaw : null,
                'nationality' => $nationality !== '' ? $nationality : null,
                'active' => true,
            ]);

            $imported++;
            if ($cprKey !== '') {
                $seenCpr[$cprKey] = true;
            }
            if ($licenseKey !== '') {
                $seenLicense[$licenseKey] = true;
            }
            if ($phoneKey !== '') {
                $seenPhone[$phoneKey] = true;
            }
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function columnRole(string $key): ?string
    {
        return match (true) {
            in_array($key, ['name', 'driver', 'driver name'], true) => 'name',
            $key === 'cpr' => 'cpr',
            in_array($key, ['license no', 'license_no', 'license number', 'licence no', 'licence number', 'license'], true) => 'license',
            in_array($key, ['license expiry', 'licence expiry', 'expiry', 'expiry date'], true) => 'expiry',
            in_array($key, ['phone', 'mobile', 'phone no', 'contact', 'tel'], true) => 'phone',
            $key === 'nationality' => 'nationality',
            default => null,
        };
    }

    /** Lenient — an unparseable date is dropped rather than imported wrong. */
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

    private function normalise(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }
}

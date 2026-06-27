<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Modules\Rental\Models\RentalCustomer;

/**
 * Imports rental customers from a CSV (an export from a previous system) into the
 * shared rental customer store. Used by both the upload controller and the
 * `rental:import-customers` command.
 *
 * Forgiving format: columns named like Name, Type, CPR / CR, Phone, E-mail are
 * read (case-insensitive); other columns are ignored. "Company" rows store the id
 * as a CR number, everyone else as a CPR. Existing customers are matched by CPR/CR
 * (then phone) and skipped, so re-importing never creates duplicates.
 */
final class CustomerImporter
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

        $existingIds = RentalCustomer::query()->whereNotNull('cpr')->where('cpr', '!=', '')
            ->pluck('cpr')->map(fn (string $v): string => $this->normalise($v))->flip();
        $existingCr = RentalCustomer::query()->whereNotNull('cr_number')->where('cr_number', '!=', '')
            ->pluck('cr_number')->map(fn (string $v): string => $this->normalise($v))->flip();
        $existingPhones = RentalCustomer::query()->whereNotNull('phone')->where('phone', '!=', '')
            ->pluck('phone')->map(fn (string $v): string => $this->normalise($v))->flip();

        $imported = 0;
        $skipped = 0;
        $seenId = [];
        $seenPhone = [];

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string) ($row[$cols['name']] ?? ''));
            if ($name === '') {
                continue;
            }

            $isCompany = isset($cols['type'])
                && str_starts_with(strtolower(trim((string) ($row[$cols['type']] ?? ''))), 'compan');
            $idRaw = isset($cols['id']) ? trim((string) ($row[$cols['id']] ?? '')) : '';
            $phoneRaw = isset($cols['phone']) ? trim((string) ($row[$cols['phone']] ?? '')) : '';
            $email = isset($cols['email']) ? trim((string) ($row[$cols['email']] ?? '')) : '';

            $idKey = $idRaw !== '' ? $this->normalise($idRaw) : '';
            $phoneKey = $phoneRaw !== '' ? $this->normalise($phoneRaw) : '';

            $dupById = $idKey !== '' && ($existingIds->has($idKey) || $existingCr->has($idKey) || isset($seenId[$idKey]));
            $dupByPhone = $idKey === '' && $phoneKey !== '' && ($existingPhones->has($phoneKey) || isset($seenPhone[$phoneKey]));
            if ($dupById || $dupByPhone) {
                $skipped++;

                continue;
            }

            RentalCustomer::query()->create([
                'name' => $name,
                'type' => $isCompany ? RentalCustomer::TYPE_COMPANY : RentalCustomer::TYPE_INDIVIDUAL,
                'cpr' => ! $isCompany && $idRaw !== '' ? $idRaw : null,
                'cr_number' => $isCompany && $idRaw !== '' ? $idRaw : null,
                'phone' => $phoneRaw !== '' ? $phoneRaw : null,
                'email' => $email !== '' ? $email : null,
                'active' => true,
            ]);

            $imported++;
            if ($idKey !== '') {
                $seenId[$idKey] = true;
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
            in_array($key, ['name', 'customer', 'customer name'], true) => 'name',
            $key === 'type' => 'type',
            in_array($key, ['cpr / cr', 'cpr/cr', 'cpr', 'cr', 'cpr or cr', 'id', 'cr / cr'], true) => 'id',
            in_array($key, ['phone', 'mobile', 'phone no', 'contact', 'tel'], true) => 'phone',
            in_array($key, ['e-mail', 'email', 'e mail', 'mail'], true) => 'email',
            default => null,
        };
    }

    private function normalise(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }
}

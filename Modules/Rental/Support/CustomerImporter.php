<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Illuminate\Support\Collection;
use Modules\Rental\Models\RentalCustomer;

/**
 * Imports rental customers from a CSV (an export from a previous system) into the
 * shared rental customer store. Used by both the upload controller and the
 * `rental:import-customers` command.
 *
 * Forgiving format: columns named like Name, Type, CPR / ID, Phone, E-mail,
 * Country, CR Number, Contact Person, Address are read (case-insensitive); other
 * columns are ignored. "Company" rows store the id as a CR number, everyone else
 * as a CPR. Country names are mapped to the ISO-2 codes the customer form stores.
 *
 * Existing customers are matched by CPR/CR, then phone, then (for rows carrying
 * neither) name — and instead of being skipped outright, a matched customer has
 * their BLANK fields filled in from the richer row (never overwriting a value
 * someone already entered), so re-importing a fuller export enriches the store
 * without ever creating duplicates.
 */
final class CustomerImporter
{
    /**
     * Country-name spellings beyond {@see RentalCustomer::countries()} that an
     * exported sheet may carry. Keys are lowercased names, values ISO-2 codes.
     *
     * @var array<string, string>
     */
    private const COUNTRY_ALIASES = [
        'uae' => 'AE', 'emirates' => 'AE',
        'usa' => 'US', 'usa / canada' => 'US', 'united states of america' => 'US', 'america' => 'US',
        'uk' => 'GB', 'england' => 'GB', 'great britain' => 'GB',
        'turkey' => 'TR', 'turkiye' => 'TR',
        'ksa' => 'SA',
        'italy' => 'IT', 'russia' => 'RU', 'brazil' => 'BR', 'australia' => 'AU',
        'romania' => 'RO', 'spain' => 'ES', 'singapore' => 'SG', 'netherlands' => 'NL',
        'austria' => 'AT', 'poland' => 'PL', 'malaysia' => 'MY', 'belgium' => 'BE',
        'china' => 'CN', 'switzerland' => 'CH', 'japan' => 'JP', 'thailand' => 'TH',
        'hong kong' => 'HK', 'morocco' => 'MA', 'ireland' => 'IE', 'south africa' => 'ZA',
        'greece' => 'GR', 'norway' => 'NO', 'mexico' => 'MX', 'sweden' => 'SE',
        'nigeria' => 'NG', 'new zealand' => 'NZ', 'tunisia' => 'TN', 'ukraine' => 'UA',
        'portugal' => 'PT', 'denmark' => 'DK', 'south korea' => 'KR', 'korea' => 'KR',
        'kenya' => 'KE', 'argentina' => 'AR',
    ];

    /** Row fields (importer key => column) a matched existing customer may have filled in when blank. */
    private const ENRICHABLE = [
        'phone' => 'phone',
        'country' => 'country',
        'email' => 'email',
        'license' => 'license_no',
        'nationality' => 'nationality',
        'crnumber' => 'cr_number',
        'contact' => 'contact_person',
        'contactphone' => 'contact_phone',
        'address' => 'address',
    ];

    /**
     * @return array{imported: int, updated: int, skipped: int}
     */
    public function import(string $path): array
    {
        $none = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $none;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return $none;
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

            return $none;
        }

        // Index every existing customer by their identifying keys so a row can
        // be matched (and enriched) instead of blindly re-created. Newly created
        // rows join the same maps, which also dedupes within the file itself.
        /** @var Collection<int, RentalCustomer> $existing */
        $existing = RentalCustomer::query()->get();
        /** @var array<string, RentalCustomer> $byId */
        $byId = [];
        /** @var array<string, RentalCustomer> $byPhone */
        $byPhone = [];
        /** @var array<string, RentalCustomer> $byName */
        $byName = [];
        foreach ($existing as $customer) {
            $this->indexCustomer($customer, $byId, $byPhone, $byName);
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string) ($row[$cols['name']] ?? ''));
            if ($name === '') {
                continue;
            }

            $value = function (string $role) use ($cols, $row): string {
                return isset($cols[$role]) ? trim((string) ($row[$cols[$role]] ?? '')) : '';
            };

            $isCompany = str_starts_with(strtolower($value('type')), 'compan');
            $idRaw = $value('id');
            $phoneRaw = $this->sanitisePhone($value('phone'));
            $crRaw = $value('crnumber');

            $fields = [
                'phone' => $phoneRaw,
                'country' => $this->countryCode($value('country')),
                'email' => $value('email'),
                'license' => $value('license'),
                'nationality' => $value('nationality'),
                'crnumber' => $crRaw !== '' ? $crRaw : ($isCompany ? $idRaw : ''),
                'contact' => $value('contact'),
                'contactphone' => $value('contactphone'),
                'address' => $value('address'),
            ];

            $idKey = $this->normalise($idRaw !== '' ? $idRaw : $crRaw);
            $phoneKey = $this->normalise($phoneRaw);
            $nameKey = $this->normalise($name);

            $match = null;
            if ($idKey !== '' && isset($byId[$idKey])) {
                $match = $byId[$idKey];
            } elseif ($phoneKey !== '' && isset($byPhone[$phoneKey])) {
                $match = $byPhone[$phoneKey];
            } elseif ($idKey === '' && $phoneKey === '' && isset($byName[$nameKey])) {
                // Only fall back to the name when the row has no better key —
                // two different people can share a name, never a CPR.
                $match = $byName[$nameKey];
            }

            if ($match !== null) {
                if (! $isCompany && $fields['crnumber'] === '' && $idRaw !== '' && ($match->cpr === null || $match->cpr === '')) {
                    $match->cpr = $idRaw;
                }
                foreach (self::ENRICHABLE as $role => $column) {
                    $current = $match->getAttribute($column);
                    if (($current === null || $current === '') && $fields[$role] !== '') {
                        $match->setAttribute($column, $fields[$role]);
                    }
                }

                if ($match->isDirty()) {
                    $match->save();
                    $this->indexCustomer($match, $byId, $byPhone, $byName);
                    $updated++;
                } else {
                    $skipped++;
                }

                continue;
            }

            $customer = RentalCustomer::query()->create([
                'name' => $name,
                'type' => $isCompany ? RentalCustomer::TYPE_COMPANY : RentalCustomer::TYPE_INDIVIDUAL,
                'cpr' => ! $isCompany && $idRaw !== '' ? $idRaw : null,
                'cr_number' => $fields['crnumber'] !== '' ? $fields['crnumber'] : null,
                'phone' => $phoneRaw !== '' ? $phoneRaw : null,
                'country' => $fields['country'] !== '' ? $fields['country'] : null,
                'email' => $fields['email'] !== '' ? $fields['email'] : null,
                'license_no' => $fields['license'] !== '' ? $fields['license'] : null,
                'nationality' => $fields['nationality'] !== '' ? $fields['nationality'] : null,
                'contact_person' => $fields['contact'] !== '' ? $fields['contact'] : null,
                'contact_phone' => $fields['contactphone'] !== '' ? $fields['contactphone'] : null,
                'address' => $fields['address'] !== '' ? $fields['address'] : null,
                'active' => true,
            ]);

            $this->indexCustomer($customer, $byId, $byPhone, $byName);
            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, RentalCustomer>  $byId
     * @param  array<string, RentalCustomer>  $byPhone
     * @param  array<string, RentalCustomer>  $byName
     */
    private function indexCustomer(RentalCustomer $customer, array &$byId, array &$byPhone, array &$byName): void
    {
        foreach ([$customer->cpr, $customer->cr_number] as $id) {
            $key = $this->normalise((string) $id);
            if ($key !== '') {
                $byId[$key] ??= $customer;
            }
        }

        $phoneKey = $this->normalise((string) $customer->phone);
        if ($phoneKey !== '') {
            $byPhone[$phoneKey] ??= $customer;
        }

        $nameKey = $this->normalise($customer->name);
        if ($nameKey !== '') {
            $byName[$nameKey] ??= $customer;
        }
    }

    /** Map a country name (or an already-ISO code) to the ISO-2 code the form stores; '' when unknown. */
    private function countryCode(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (strlen($raw) === 2 && ctype_alpha($raw)) {
            return strtoupper($raw);
        }

        $key = strtolower($raw);
        foreach (RentalCustomer::countries() as $country) {
            if (strtolower($country['name']) === $key) {
                return $country['code'];
            }
        }

        return self::COUNTRY_ALIASES[$key] ?? '';
    }

    /**
     * Exported sheets sometimes jam two numbers into one cell
     * ("+96659…+44…"); keep the first complete number.
     */
    private function sanitisePhone(string $raw): string
    {
        $raw = trim($raw);
        $second = $raw === '' ? false : strpos($raw, '+', 1);

        return $second !== false ? trim(substr($raw, 0, $second)) : $raw;
    }

    private function columnRole(string $key): ?string
    {
        return match (true) {
            in_array($key, ['name', 'customer', 'customer name'], true) => 'name',
            in_array($key, ['type', 'customer type'], true) => 'type',
            in_array($key, ['cpr / cr', 'cpr/cr', 'cpr', 'cr', 'cpr or cr', 'id', 'cr / cr', 'cpr / id', 'cpr/id'], true) => 'id',
            in_array($key, ['phone', 'mobile', 'phone no', 'contact', 'tel'], true) => 'phone',
            in_array($key, ['e-mail', 'email', 'e mail', 'mail'], true) => 'email',
            $key === 'country' => 'country',
            in_array($key, ['licence no.', 'license no.', 'licence no', 'license no', 'licence', 'license'], true) => 'license',
            $key === 'nationality' => 'nationality',
            in_array($key, ['cr number', 'cr no', 'cr no.'], true) => 'crnumber',
            $key === 'contact person' => 'contact',
            in_array($key, ['contact person phone', 'contact phone'], true) => 'contactphone',
            $key === 'address' => 'address',
            default => null,
        };
    }

    private function normalise(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }
}

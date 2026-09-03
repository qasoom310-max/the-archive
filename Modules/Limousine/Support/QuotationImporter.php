<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoQuotation;
use Throwable;

/**
 * Imports quotations from a CSV (an export from a previous system) into the
 * quotations list.
 *
 * The expected columns match this screen's own export (Reference, Customer,
 * Valid until, Fare, Status). A quote is a price offered, not yet billed, so
 * — unlike Bookings — there is no invoice or receipt to backfill here.
 */
final class QuotationImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference',
        'customer' => 'customer',
        'valid until' => 'valid_until', 'expiry' => 'valid_until',
        'fare' => 'fare', 'total' => 'fare', 'amount' => 'fare',
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

        if (! isset($cols['customer']) || ! isset($cols['fare'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $customerName = trim((string) ($row[$cols['customer']] ?? ''));
            $fareRaw = trim((string) ($row[$cols['fare']] ?? ''));

            if ($customerName === '' || $fareRaw === '') {
                continue;
            }

            $fare = round((float) str_replace(',', '', $fareRaw), 3);
            $validUntil = isset($cols['valid_until']) ? $this->parseDate((string) ($row[$cols['valid_until']] ?? '')) : null;

            if ($this->alreadyImported($customerName, $fare, $validUntil)) {
                $skipped++;

                continue;
            }

            $customer = $this->resolveCustomer($customerName);

            LimoQuotation::query()->create([
                'customer_id' => $customer->id,
                'fare' => $fare,
                'valid_until' => $validUntil,
                'status' => $this->status(isset($cols['status']) ? (string) ($row[$cols['status']] ?? '') : ''),
                'notes' => __('Imported from previous system.'),
            ]);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function alreadyImported(string $customerName, float $fare, ?Carbon $validUntil): bool
    {
        $query = LimoQuotation::query()
            ->whereHas('customer', fn ($c) => $c->where('name', $customerName))
            ->whereBetween('fare', [$fare - 0.001, $fare + 0.001]);

        if ($validUntil !== null) {
            $query->whereDate('valid_until', $validUntil->toDateString());
        }

        return $query->exists();
    }

    private function resolveCustomer(string $name): LimoCustomer
    {
        $existing = LimoCustomer::query()->where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        return LimoCustomer::query()->create(['name' => $name, 'active' => true]);
    }

    private function status(string $value): string
    {
        return match (strtolower(trim($value))) {
            'sent' => LimoQuotation::STATUS_SENT,
            'accepted' => LimoQuotation::STATUS_ACCEPTED,
            'converted' => LimoQuotation::STATUS_CONVERTED,
            'declined' => LimoQuotation::STATUS_DECLINED,
            default => LimoQuotation::STATUS_DRAFT,
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

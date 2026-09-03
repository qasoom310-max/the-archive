<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoPettyAdvance;
use Throwable;

/**
 * Imports petty-cash advances from a CSV (an export from a previous system)
 * into the petty-cash list.
 *
 * The expected columns match this screen's own export (Reference, Driver,
 * Date, Given, Receipts, Status). A row lands directly at its recorded
 * status and totals — never through {@see
 * \Modules\Limousine\Services\PettyCash::settle()}, which also writes one
 * {@see \Modules\Limousine\Models\LimoExpense} row per paper receipt LINE;
 * this importer has no line-level detail to give it (only the settled
 * totals), so it sets the advance's own shortfall/excess directly with the
 * same arithmetic settle() uses and creates no lines, no expense rows.
 * Historical rows default to Cleared, already settled.
 */
final class PettyCashImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference',
        'driver' => 'driver',
        'date' => 'date',
        'given' => 'amount', 'amount' => 'amount',
        'receipts' => 'receipts',
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

        if (! isset($cols['driver']) || ! isset($cols['amount'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $driverName = trim((string) ($row[$cols['driver']] ?? ''));
            $amountRaw = trim((string) ($row[$cols['amount']] ?? ''));

            if ($driverName === '' || $amountRaw === '') {
                continue;
            }

            $amount = round((float) str_replace(',', '', $amountRaw), 3);
            $date = isset($cols['date']) ? $this->parseDate((string) ($row[$cols['date']] ?? '')) : null;

            if ($this->alreadyImported($driverName, $amount, $date)) {
                $skipped++;

                continue;
            }

            // The column is NOT NULL on this table (unlike every other
            // importer's date, which is nullable) — an unparseable date
            // still needs a value to satisfy the schema.
            $date ??= Carbon::now();

            $driver = $this->resolveDriver($driverName);
            $status = $this->status(isset($cols['status']) ? (string) ($row[$cols['status']] ?? '') : '');

            $attrs = [
                'driver_id' => $driver->id,
                'date' => $date,
                'amount' => $amount,
                'status' => $status,
                'notes' => __('Imported from previous system.'),
            ];

            if ($status === LimoPettyAdvance::STATUS_CLEARED) {
                $receiptsRaw = isset($cols['receipts']) ? trim((string) ($row[$cols['receipts']] ?? '')) : '';
                $receipts = $receiptsRaw !== '' ? round((float) str_replace(',', '', $receiptsRaw), 3) : $amount;

                $attrs['receipts_total'] = $receipts;
                $attrs['shortfall'] = round(max(0.0, $amount - $receipts), 3);
                $attrs['excess'] = round(max(0.0, $receipts - $amount), 3);
                $attrs['settled_at'] = Carbon::now();
                $attrs['settled_by'] = __('Import (previous system)');
            }

            LimoPettyAdvance::query()->create($attrs);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function alreadyImported(string $driverName, float $amount, ?Carbon $date): bool
    {
        $query = LimoPettyAdvance::query()
            ->whereHas('driver', fn ($d) => $d->where('name', $driverName))
            ->whereBetween('amount', [$amount - 0.001, $amount + 0.001]);

        if ($date !== null) {
            $query->whereDate('date', $date->toDateString());
        }

        return $query->exists();
    }

    private function resolveDriver(string $name): LimoDriver
    {
        $existing = LimoDriver::query()->where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        return LimoDriver::query()->create(['name' => $name, 'active' => true]);
    }

    private function status(string $value): string
    {
        return match (strtolower(trim($value))) {
            'issued' => LimoPettyAdvance::STATUS_ISSUED,
            'confirmed' => LimoPettyAdvance::STATUS_CONFIRMED,
            // Historical rows are, overwhelmingly, advances already settled.
            default => LimoPettyAdvance::STATUS_CLEARED,
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

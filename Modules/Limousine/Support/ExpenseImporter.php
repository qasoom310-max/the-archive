<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoExpense;
use Throwable;

/**
 * Imports running expenses from a CSV (an export from a previous system)
 * into the expenses list.
 *
 * The expected columns match this screen's own export (Reference, Date,
 * Category, Paid to, Amount). A plain running cost — no order/trip, no
 * workflow — so a row is created directly at the figures given.
 */
final class ExpenseImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference',
        'date' => 'date',
        'category' => 'category',
        'paid to' => 'payee', 'payee' => 'payee',
        'amount' => 'amount',
        'notes' => 'notes',
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

        if (! isset($cols['amount'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $amountRaw = trim((string) ($row[$cols['amount']] ?? ''));

            if ($amountRaw === '') {
                continue;
            }

            $amount = round((float) str_replace(',', '', $amountRaw), 3);
            $date = isset($cols['date']) ? $this->parseDate((string) ($row[$cols['date']] ?? '')) : null;
            $payee = isset($cols['payee']) ? $this->orNull((string) ($row[$cols['payee']] ?? '')) : null;

            if ($this->alreadyImported($amount, $date, $payee)) {
                $skipped++;

                continue;
            }

            LimoExpense::query()->create([
                'date' => $date,
                'category' => $this->category(isset($cols['category']) ? (string) ($row[$cols['category']] ?? '') : ''),
                'amount' => $amount,
                'payee' => $payee,
                'notes' => isset($cols['notes']) ? $this->orNull((string) ($row[$cols['notes']] ?? '')) : __('Imported from previous system.'),
            ]);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function alreadyImported(float $amount, ?Carbon $date, ?string $payee): bool
    {
        $query = LimoExpense::query()->whereBetween('amount', [$amount - 0.001, $amount + 0.001]);

        if ($date !== null) {
            $query->whereDate('date', $date->toDateString());
        }

        if ($payee !== null) {
            $query->where('payee', $payee);
        }

        return $query->exists();
    }

    private function category(string $value): string
    {
        return match (strtolower(trim($value))) {
            'fuel' => 'fuel',
            'wash', 'car wash', 'car wash & cleaning', 'cleaning' => 'wash',
            'parking' => 'parking',
            'tolls', 'toll' => 'tolls',
            'insurance' => 'insurance',
            'spare parts', 'spare_parts', 'parts' => 'spare_parts',
            'food' => 'food',
            'office', 'office expenses' => 'office',
            'driver pay', 'driver_pay' => 'driver_pay',
            'maintenance' => 'maintenance',
            'salaries', 'salary' => 'salaries',
            'rent' => 'rent',
            'fees', 'fee' => 'fees',
            default => 'other',
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

    private function orNull(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}

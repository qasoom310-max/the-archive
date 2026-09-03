<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Illuminate\Support\Carbon;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;
use Throwable;

/**
 * Imports invoices from a CSV (an export from a previous system) into the
 * invoices list.
 *
 * The expected columns match this screen's own export (Reference, Customer,
 * Issued, Total, Paid, Balance, Status). Lands as SETTLED HISTORY — a plain
 * invoice at the figures the old system already recorded, with no order
 * behind it (order_id stays null) and no recompute against today's pricing.
 * A receipt against one of these is a separate import that finds it by
 * reference.
 */
final class InvoiceImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference',
        'customer' => 'customer',
        'issued' => 'issue_date', 'issue date' => 'issue_date', 'date' => 'issue_date',
        'total' => 'total', 'amount' => 'total',
        'paid' => 'amount_paid', 'amount paid' => 'amount_paid', 'received' => 'amount_paid',
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

        if (! isset($cols['customer']) || ! isset($cols['total'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $customerName = trim((string) ($row[$cols['customer']] ?? ''));
            $totalRaw = trim((string) ($row[$cols['total']] ?? ''));

            if ($customerName === '' || $totalRaw === '') {
                continue;
            }

            $total = round((float) str_replace(',', '', $totalRaw), 3);
            $issueDate = isset($cols['issue_date']) ? $this->parseDate((string) ($row[$cols['issue_date']] ?? '')) : null;

            if ($this->alreadyImported($customerName, $total, $issueDate)) {
                $skipped++;

                continue;
            }

            $customer = $this->resolveCustomer($customerName);
            $paidRaw = isset($cols['amount_paid']) ? trim((string) ($row[$cols['amount_paid']] ?? '')) : '';
            $paid = $paidRaw !== '' ? min(round((float) str_replace(',', '', $paidRaw), 3), $total) : 0.0;
            $statusRaw = isset($cols['status']) ? (string) ($row[$cols['status']] ?? '') : '';

            RentalInvoice::query()->create([
                'customer_id' => $customer->id,
                'issue_date' => $issueDate,
                'due_date' => $issueDate,
                'subtotal' => $total,
                'total' => $total,
                'amount_paid' => $paid,
                'status' => $this->status($statusRaw, $paid, $total),
                'notes' => __('Imported from previous system.'),
            ]);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function alreadyImported(string $customerName, float $total, ?Carbon $issueDate): bool
    {
        $query = RentalInvoice::query()
            ->whereHas('customer', fn ($c) => $c->where('name', $customerName))
            ->whereBetween('total', [$total - 0.001, $total + 0.001]);

        if ($issueDate !== null) {
            $query->whereDate('issue_date', $issueDate->toDateString());
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

    private function status(string $value, float $paid, float $total): string
    {
        return match (strtolower(trim($value))) {
            'unpaid' => RentalInvoice::STATUS_UNPAID,
            'partial' => RentalInvoice::STATUS_PARTIAL,
            'paid' => RentalInvoice::STATUS_PAID,
            default => match (true) {
                $paid <= 0.0005 => RentalInvoice::STATUS_UNPAID,
                $paid + 0.0005 < $total => RentalInvoice::STATUS_PARTIAL,
                default => RentalInvoice::STATUS_PAID,
            },
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

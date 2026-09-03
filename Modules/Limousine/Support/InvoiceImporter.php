<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Throwable;

/**
 * Imports invoices from a CSV (an export from a previous system) into the
 * invoices list.
 *
 * The expected columns match this screen's own export (Reference, Customer,
 * Issued, Total, Paid, Balance, Status). Lands as SETTLED HISTORY — a plain
 * invoice at the figures the old system already recorded, with no trip
 * behind it (booking_id/quotation_id stay null) and no recompute against
 * today's pricing (followTotal() is deliberately never called). A receipt
 * against one of these is a separate import that finds it by reference.
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

            LimoInvoice::query()->create([
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
        $query = LimoInvoice::query()
            ->whereHas('customer', fn ($c) => $c->where('name', $customerName))
            ->whereBetween('total', [$total - 0.001, $total + 0.001]);

        if ($issueDate !== null) {
            $query->whereDate('issue_date', $issueDate->toDateString());
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

    private function status(string $value, float $paid, float $total): string
    {
        return match (strtolower(trim($value))) {
            'unpaid' => LimoInvoice::STATUS_UNPAID,
            'partial' => LimoInvoice::STATUS_PARTIAL,
            'paid' => LimoInvoice::STATUS_PAID,
            default => match (true) {
                $paid <= 0.0005 => LimoInvoice::STATUS_UNPAID,
                $paid + 0.0005 < $total => LimoInvoice::STATUS_PARTIAL,
                default => LimoInvoice::STATUS_PAID,
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

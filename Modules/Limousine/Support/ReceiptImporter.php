<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Throwable;

/**
 * Imports receipts from a CSV (an export from a previous system) into the
 * receipts list.
 *
 * The expected columns match this screen's own export (Reference, Customer,
 * Invoice, Date, Method, Amount). When the Invoice column names an invoice
 * already on file (by reference — including one just imported ahead of it in
 * the same run), the receipt is linked to it and LimoReceipt's own `created`
 * hook recomputes that invoice's amount_paid/status from the sum of its
 * receipts — a plain sum, not a live-pricing recompute, so this is still
 * settled history. An unmatched or blank Invoice column leaves the receipt
 * standalone. Historical money has nothing left to check against a bank
 * statement, so — same as the booking-import backfill — it lands already
 * confirmed rather than sitting in the accountant's queue for years-old trips.
 */
final class ReceiptImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference',
        'customer' => 'customer',
        'invoice' => 'invoice',
        'date' => 'date',
        'method' => 'method',
        'amount' => 'amount',
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

        if (! isset($cols['customer']) || ! isset($cols['amount'])) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0];
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $customerName = trim((string) ($row[$cols['customer']] ?? ''));
            $amountRaw = trim((string) ($row[$cols['amount']] ?? ''));

            if ($customerName === '' || $amountRaw === '') {
                continue;
            }

            $amount = round((float) str_replace(',', '', $amountRaw), 3);
            $date = isset($cols['date']) ? $this->parseDate((string) ($row[$cols['date']] ?? '')) : null;

            if ($this->alreadyImported($customerName, $amount, $date)) {
                $skipped++;

                continue;
            }

            $customer = $this->resolveCustomer($customerName);
            $invoiceRef = isset($cols['invoice']) ? trim((string) ($row[$cols['invoice']] ?? '')) : '';
            $invoice = $invoiceRef !== '' ? LimoInvoice::query()->where('reference', $invoiceRef)->first() : null;

            LimoReceipt::query()->create([
                'invoice_id' => $invoice?->id,
                'customer_id' => $customer->id,
                'date' => $date,
                'amount' => $amount,
                'balance_after' => $invoice !== null ? round(max(0.0, $invoice->total - $invoice->amount_paid - $amount), 3) : null,
                'method' => $this->method(isset($cols['method']) ? (string) ($row[$cols['method']] ?? '') : ''),
                'auto' => false,
                'notes' => __('Imported from previous system.'),
                'confirmed_at' => Carbon::now(),
                'confirmed_by' => __('Import (previous system)'),
            ]);

            $imported++;
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function alreadyImported(string $customerName, float $amount, ?Carbon $date): bool
    {
        $query = LimoReceipt::query()
            ->whereHas('customer', fn ($c) => $c->where('name', $customerName))
            ->whereBetween('amount', [$amount - 0.001, $amount + 0.001]);

        if ($date !== null) {
            $query->whereDate('date', $date->toDateString());
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

    private function method(string $value): string
    {
        return match (strtolower(trim($value))) {
            'card' => 'card',
            'benefit' => 'benefit',
            'transfer', 'bank transfer' => 'transfer',
            default => 'cash',
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

<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoQuotation;
use RuntimeException;
use Throwable;

/**
 * Brings quotations over from the previous limousine system's own
 * quotations list export (Sl No. / # / Date / Customer / Requested Person /
 * Added By / Actions) — a bare register with no pricing, vehicle or leg data
 * at all, unlike a quote raised in this app. It exists purely so the
 * historical count of quotes issued isn't lost, matching
 * {@see LegacyInvoiceImporter} and {@see LegacyReceiptImporter}'s shape.
 *
 * The old "#" is NOT unique in the export — several rows share a number
 * (same customer and date, only "Requested Person" differing), which reads
 * as the old system's own numbering glitch rather than a real duplicate quote.
 * So unlike the invoice importer, this can't use the number as the row's id;
 * it's kept verbatim in `reference` ("QT/0560", not the app's own 5-digit
 * "QT/00560" shape — same reasoning as the receipts importer preserving
 * "L-RCPT12968" as-is) and a row is recognised as already on file by matching
 * reference + requested-by + date together, so the genuinely duplicate-numbered
 * rows (different requested person) still both import, and a re-run against a
 * fresher export only picks up what's new.
 *
 * "Added By" is the old system's login that typed the quote up — the same
 * kind of free-text name `limo_quotations.prepared_by` already holds for a
 * quote raised in this app, so it's a direct fit for historical rows too.
 *
 * With no evidence either way of what became of a historical quote, it is
 * recorded as `sent` (a quotation register exists because a price was given
 * to a customer, not because it merely sat as a draft) rather than guessed as
 * accepted, declined or converted.
 */
final class LegacyQuotationImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        '#' => 'number',
        'date' => 'date',
        'customer' => 'customer',
        'requested person' => 'requested_by',
        'added by' => 'prepared_by',
    ];

    /** @var array<string, LimoCustomer> lower-case name → customer */
    private array $byName = [];

    /**
     * @return array{imported: int, skipped: int, lines: list<string>}
     */
    public function import(string $path, bool $pretend = false): array
    {
        $rows = $this->readRows($path);
        $this->indexCustomers();

        $result = ['imported' => 0, 'skipped' => 0, 'lines' => []];

        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                $result['lines'][] = $this->importRow($row, $result);
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // A dry run takes the exact same path, then throws every write away.
        $pretend ? DB::rollBack() : DB::commit();

        return $result;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array{imported: int, skipped: int, lines: list<string>}  $result
     */
    private function importRow(array $row, array &$result): string
    {
        $number = (int) preg_replace('/\D/', '', $row['number'] ?? '');
        $customerName = $this->clean($row['customer'] ?? '') ?? '';
        $date = $this->parseDate($row['date'] ?? '');
        $requestedBy = $this->clean($row['requested_by'] ?? '');
        $preparedBy = $this->clean($row['prepared_by'] ?? '');
        $reference = $number > 0 ? sprintf('QT/%04d', $number) : '';

        $label = sprintf('%s (%s)', $reference !== '' ? $reference : '?', $customerName !== '' ? $customerName : '?');

        if ($number <= 0 || $customerName === '') {
            $result['skipped']++;

            return "SKIP    {$label} — missing quotation number or customer";
        }

        $query = LimoQuotation::query()->where('reference', $reference);
        $requestedBy !== null ? $query->where('requested_by', $requestedBy) : $query->whereNull('requested_by');
        $date !== null ? $query->whereDate('quote_date', $date->toDateString()) : $query->whereNull('quote_date');

        if ($query->exists()) {
            $result['skipped']++;

            return "EXISTS  {$label} — already on file";
        }

        [$customer, $how] = $this->resolveCustomer($customerName);

        $quotation = new LimoQuotation;
        $quotation->forceFill([
            'reference' => $reference,
            'quote_date' => $date,
            'customer_id' => $customer->id,
            'requested_by' => $requestedBy,
            'prepared_by' => $preparedBy,
            'status' => LimoQuotation::STATUS_SENT,
            'sent_at' => $date,
            'fare' => 0,
        ]);
        $quotation->created_at = $date;
        $quotation->save();

        $result['imported']++;

        return "NEW     {$label} — {$how}";
    }

    /**
     * @return list<array<string, string>>
     */
    private function readRows(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return [];
        }

        $cols = [];
        foreach ($header as $i => $name) {
            $key = self::HEADER_MAP[strtolower(trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $name)))] ?? null;
            if ($key !== null && ! isset($cols[$key])) {
                $cols[$key] = $i;
            }
        }

        foreach (['number', 'date', 'customer'] as $required) {
            if (! isset($cols[$required])) {
                fclose($handle);

                throw new RuntimeException("The file has no [{$required}] column.");
            }
        }

        $rows = [];
        while (($raw = fgetcsv($handle)) !== false) {
            if ($raw === [null]) {
                continue;
            }

            $row = [];
            foreach ($cols as $key => $i) {
                $row[$key] = trim((string) ($raw[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function indexCustomers(): void
    {
        $this->byName = [];

        LimoCustomer::query()->orderBy('id')->each(function (LimoCustomer $customer): void {
            $this->byName[strtolower(trim((string) $customer->name))] ??= $customer;
        });
    }

    /**
     * @return array{0: LimoCustomer, 1: string}
     */
    private function resolveCustomer(string $name): array
    {
        $existing = $this->byName[strtolower($name)] ?? null;
        if ($existing !== null) {
            return [$existing, 'matched by name'];
        }

        $customer = LimoCustomer::query()->create([
            'name' => $name,
            'type' => preg_match('/\b(w\.?l\.?l|company|co\.|travel|trad(e|ing)|group|hotel|llc|b\.?s\.?c|est)\b/i', $name) === 1
                ? LimoCustomer::TYPE_COMPANY
                : 'individual',
            'active' => true,
        ]);
        $this->byName[strtolower($name)] = $customer;

        return [$customer, 'new customer'];
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d-m-Y', $value)?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function clean(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value !== '' ? $value : null;
    }
}

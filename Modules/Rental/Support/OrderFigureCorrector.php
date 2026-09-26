<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Modules\Rental\Models\RentalOrder;

/**
 * Puts the previous system's money figures back on rental orders the
 * historical import brought over without them.
 *
 * Those orders arrived with no rate, so their Amount and Total read 0.00 —
 * and an order whose total is 0 counts as paid, so they also read PAID while
 * the customer still owed money. This reads the old system's orders export
 * (the "Active Orders" list's CSV: RA#, Amount, VAT, Total, Receipt, Balance,
 * Deposit, …) and copies those figures onto the order with the same RA#.
 *
 * The rate is set too — Amount spread over the order's billable units — so a
 * later edit or car return, which re-prices the order from its rate, lands on
 * (within a few fils of) the old Amount instead of wiping it back to 0.
 *
 * "Extra" is reported but not written: in the old system it sits OUTSIDE the
 * total (Amount + VAT = Total), so folding it into this order's extra charge
 * would change the total the owner is trying to match.
 */
final class OrderFigureCorrector
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'ra#' => 'reference', 'ra' => 'reference', 'ra no' => 'reference', 'ra no.' => 'reference', 'reference' => 'reference',
        'amount' => 'amount',
        'vat' => 'vat',
        'total' => 'total',
        'receipt' => 'receipt', 'received' => 'receipt',
        'balance' => 'balance',
        'extra' => 'extra',
        'deposit' => 'deposit',
    ];

    /**
     * @return array{rows: int, updated: int, unchanged: int, missing: list<string>, changes: list<string>, not_in_file: list<string>, activated: int, closed: int}
     */
    public function correct(string $path, bool $pretend = false, bool $syncActive = false): array
    {
        $result = ['rows' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => [], 'changes' => [], 'not_in_file' => [], 'activated' => 0, 'closed' => 0];

        $rows = $this->read($path);
        $seen = [];

        foreach ($rows as $row) {
            $result['rows']++;
            $reference = $row['reference'];
            $seen[] = $reference;

            $order = RentalOrder::query()->where('reference', $reference)->first();
            if ($order === null) {
                $result['missing'][] = $reference;

                continue;
            }

            $before = $this->snapshot($order);
            $this->apply($order, $row);

            if ($syncActive && $order->state === RentalOrder::STATE_CLOSED) {
                $order->state = RentalOrder::STATE_ACTIVE;
                $result['activated']++;
            }

            $after = $this->snapshot($order);
            if ($before === $after) {
                $result['unchanged']++;

                continue;
            }

            $result['updated']++;
            $result['changes'][] = sprintf(
                '%s: amount %s→%s, VAT %s→%s, total %s→%s, received %s→%s, balance %s→%s, deposit %s→%s, %s→%s%s',
                $reference,
                $before['subtotal'], $after['subtotal'], $before['vat_amount'], $after['vat_amount'],
                $before['total'], $after['total'], $before['advance_amount'], $after['advance_amount'],
                $before['balance'], $after['balance'], $before['deposit'], $after['deposit'],
                $before['payment_status'], $after['payment_status'],
                $row['extra'] > 0 ? sprintf(' (old Extra %.3f not written — outside the old total)', $row['extra']) : '',
            );

            if (! $pretend) {
                // Quietly: these are corrected historical figures, not a new
                // sale — no hook should re-price, re-number or notify.
                $order->saveQuietly();
            }
        }

        if ($syncActive) {
            // An order the ERP holds as active that the old system's active
            // list does not include has been returned there: close it here too.
            $stale = RentalOrder::query()
                ->where('state', RentalOrder::STATE_ACTIVE)
                ->whereNotIn('reference', $seen)
                ->get();

            foreach ($stale as $order) {
                $result['not_in_file'][] = (string) $order->reference;
                $result['closed']++;
                if (! $pretend) {
                    $order->state = RentalOrder::STATE_CLOSED;
                    $order->saveQuietly();
                }
            }
        }

        return $result;
    }

    /**
     * @param  array{reference: string, amount: float, vat: float, total: float, receipt: float, balance: float, extra: float, deposit: ?float}  $row
     */
    private function apply(RentalOrder $order, array $row): void
    {
        $order->subtotal = $row['amount'];
        $order->vat_amount = $row['vat'];
        $order->total = $row['total'];
        $order->advance_amount = $row['receipt'];
        $order->balance = round(max(0.0, $row['balance']), 3);
        if ($row['deposit'] !== null) {
            $order->deposit = $row['deposit'];
        }
        $order->discount = 0.0;

        if ($row['amount'] > 0) {
            $order->vat_rate = round($row['vat'] / $row['amount'] * 100, 2);
        }

        $units = $order->billableUnits();
        if ($units > 0) {
            $order->rate = round($row['amount'] / $units, 3);
        }

        $order->payment_status = match (true) {
            $row['balance'] <= 0.0005 => RentalOrder::PAYMENT_PAID,
            $row['receipt'] > 0.0 => RentalOrder::PAYMENT_PARTIAL,
            default => RentalOrder::PAYMENT_UNPAID,
        };

        // A confirmation of payment cannot stand on an order that is not paid.
        if ($order->payment_status !== RentalOrder::PAYMENT_PAID) {
            $order->payment_confirmed = false;
            $order->confirmed_by_user_id = null;
            $order->confirmed_at = null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(RentalOrder $order): array
    {
        $fmt = static fn (mixed $v): string => number_format((float) $v, 3, '.', '');

        return [
            'subtotal' => $fmt($order->subtotal),
            'vat_amount' => $fmt($order->vat_amount),
            'total' => $fmt($order->total),
            'advance_amount' => $fmt($order->advance_amount),
            'balance' => $fmt($order->balance),
            'deposit' => $fmt($order->deposit),
            'rate' => $fmt($order->rate),
            'payment_status' => (string) $order->payment_status,
            'state' => (string) $order->state,
        ];
    }

    /**
     * @return list<array{reference: string, amount: float, vat: float, total: float, receipt: float, balance: float, extra: float, deposit: ?float}>
     */
    private function read(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return [];
        }
        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);

        $cols = [];
        foreach ($header as $i => $name) {
            $key = self::HEADER_MAP[strtolower(trim((string) $name))] ?? null;
            if ($key !== null && ! isset($cols[$key])) {
                $cols[$key] = $i;
            }
        }

        if (! isset($cols['reference'], $cols['amount'], $cols['total'])) {
            fclose($handle);

            return [];
        }

        $num = static function (array $row, array $cols, string $key): float {
            $raw = isset($cols[$key]) ? trim((string) ($row[$cols[$key]] ?? '')) : '';

            return round((float) str_replace([',', ' '], '', $raw), 3);
        };

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $reference = strtoupper(trim((string) ($row[$cols['reference']] ?? '')));
            if ($reference === '') {
                continue;
            }

            $rows[] = [
                'reference' => $reference,
                'amount' => $num($row, $cols, 'amount'),
                'vat' => $num($row, $cols, 'vat'),
                'total' => $num($row, $cols, 'total'),
                'receipt' => $num($row, $cols, 'receipt'),
                'balance' => $num($row, $cols, 'balance'),
                'extra' => $num($row, $cols, 'extra'),
                'deposit' => isset($cols['deposit']) ? $num($row, $cols, 'deposit') : null,
            ];
        }
        fclose($handle);

        return $rows;
    }
}

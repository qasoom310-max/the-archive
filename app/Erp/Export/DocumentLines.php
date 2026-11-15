<?php

declare(strict_types=1);

namespace App\Erp\Export;

use App\Erp\Views\ValueFormat;

/**
 * Helpers for the lists whose PDF is a real document (invoices, quotations):
 * their Excel / CSV / Print carry the document's own lines and columns, so
 * a downloaded sheet reads like the paper the customer gets, totals included.
 */
final class DocumentLines
{
    /**
     * The totals box at the foot of a document, as sheet rows: one empty row,
     * then one row per figure with its label in `$labelKey` and its amount in
     * `$amountKey`. A null figure is left out.
     *
     * @param  array<string, float|null>  $figures  label => amount
     * @return list<array<string, string>>
     */
    public static function footer(array $figures, string $labelKey, string $amountKey): array
    {
        $rows = [[]];
        foreach ($figures as $label => $amount) {
            if ($amount === null) {
                continue;
            }
            $rows[] = [$labelKey => $label, $amountKey => ValueFormat::money(round($amount, 3))];
        }

        return $rows;
    }

    /**
     * An extra sold with a trip, worded as the document prints it:
     * "+ Child seat × 2 (5.00 BD each)".
     *
     * @param  array{name: string, qty: int, price: float}  $extra
     */
    public static function extraLabel(array $extra): string
    {
        return '+ ' . $extra['name'] . ' × ' . $extra['qty'] . ' (' . ValueFormat::money($extra['price']) . ' ' . __('each') . ')';
    }

    /** "2", "1.5": a count as the documents print it. */
    public static function quantity(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}

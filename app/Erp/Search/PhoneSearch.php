<?php

declare(strict_types=1);

namespace App\Erp\Search;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Finding a record by phone number however either side was written.
 *
 * "+962 7 9678 1946", "0796781946" and "962796781946" are one number, but a
 * plain LIKE compares them letter for letter, so a number typed with its
 * country code or spaces found nothing. Both sides are reduced to digits and
 * matched on the ending — the same rule as PosCustomerDiscount::findForPhone().
 */
final class PhoneSearch
{
    /** Digits of the number that identify it; shorter than this is not a phone. */
    public const MIN_DIGITS = 7;

    /** How much of the ending is compared — enough to be unique, short enough to skip a country code. */
    private const ENDING = 8;

    /** The digits to look for, or null when the term is not a phone number. */
    public static function ending(string $term): ?string
    {
        $digits = ltrim((string) preg_replace('/\D/', '', $term), '0');
        if (strlen($digits) < self::MIN_DIGITS || preg_match('/[\p{L}]/u', $term) === 1) {
            return null;
        }

        return substr($digits, -self::ENDING);
    }

    /**
     * OR a digits-only match of $column against the term.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder  $query
     */
    public static function orWhere(Builder $query, string $column, string $ending): void
    {
        $wrapped = $query->getGrammar()->wrap($column);
        $digits = "COALESCE({$wrapped}, '')";
        foreach ([' ', '+', '-', '(', ')', '.', '/'] as $char) {
            $digits = "REPLACE({$digits}, '{$char}', '')";
        }

        $query->orWhereRaw("{$digits} LIKE ?", ['%' . $ending . '%']);
    }
}

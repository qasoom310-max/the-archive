<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

/**
 * A phone number as WhatsApp needs it: international digits only, no "+".
 *
 * Numbers are typed every way in this office — "+973 3912 3456", "0097339123456",
 * or just the local eight digits "39123456" — and Meta delivers only to the full
 * international form. A bare eight-digit number is a Bahrain number (the
 * country's numbers are all eight digits), so it gets 973 in front; "00" is the
 * international prefix and is dropped. Anything else is left as its digits.
 */
final class WhatsAppNumber
{
    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 8) {
            $digits = '973' . $digits;
        }

        // Too short to be anyone's number — a slip, not a recipient; longer than
        // any international number (15 digits) is two numbers run together.
        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : '';
    }

    /**
     * The first phone number in a free-text field. A contact box often holds
     * more than a number ("Ahmed 3912 3456 / office 1771 0000"), and squashing
     * all of its digits together would message a number nobody has.
     */
    public static function first(string $text): string
    {
        if (preg_match('/\+?\d[\d\s\-().]{6,}\d/', $text, $match) !== 1) {
            return '';
        }

        return self::normalize($match[0]);
    }
}

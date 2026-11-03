<?php

declare(strict_types=1);

namespace App\Erp\Pdf;

/**
 * Makes Arabic readable in a DomPDF document.
 *
 * DomPDF neither joins Arabic letters nor lays text out right-to-left, so an
 * Arabic customer name printed as loose letters in the wrong order. This class
 * does both jobs before the HTML reaches DomPDF:
 *
 * 1. Shaping: each letter becomes its joined form (initial / medial / final /
 *    isolated) from the Arabic Presentation Forms, with lam-alef ligatures.
 *    DejaVu Sans, the font every PDF here uses, carries all of those glyphs.
 * 2. Order: the text is written out in VISUAL order (a simplified Unicode bidi
 *    pass), so Arabic reads right-to-left while numbers and Latin words inside
 *    it keep reading left-to-right.
 *
 * DomPDF wraps a line left-to-right, which would put the end of a long Arabic
 * sentence on the first line. So a long Arabic text is split into lines of at
 * most {@see LINE_CHARS} characters here, in reading order, joined with <br>.
 *
 * Text with no Arabic in it is returned untouched.
 */
final class ArabicText
{
    /** The longest Arabic line left for DomPDF to lay out (it fits the narrowest field). */
    public const LINE_CHARS = 24;

    /**
     * Each letter's first presentation form and how many forms it has:
     * 4 = isolated, final, initial, medial (joins on both sides);
     * 2 = isolated, final (joins to the letter before only);
     * 1 = isolated (never joins).
     *
     * @var array<int, array{0: int, 1: int}>
     */
    private const FORMS = [
        0x0621 => [0xFE80, 1], 0x0622 => [0xFE81, 2], 0x0623 => [0xFE83, 2], 0x0624 => [0xFE85, 2],
        0x0625 => [0xFE87, 2], 0x0626 => [0xFE89, 4], 0x0627 => [0xFE8D, 2], 0x0628 => [0xFE8F, 4],
        0x0629 => [0xFE93, 2], 0x062A => [0xFE95, 4], 0x062B => [0xFE99, 4], 0x062C => [0xFE9D, 4],
        0x062D => [0xFEA1, 4], 0x062E => [0xFEA5, 4], 0x062F => [0xFEA9, 2], 0x0630 => [0xFEAB, 2],
        0x0631 => [0xFEAD, 2], 0x0632 => [0xFEAF, 2], 0x0633 => [0xFEB1, 4], 0x0634 => [0xFEB5, 4],
        0x0635 => [0xFEB9, 4], 0x0636 => [0xFEBD, 4], 0x0637 => [0xFEC1, 4], 0x0638 => [0xFEC5, 4],
        0x0639 => [0xFEC9, 4], 0x063A => [0xFECD, 4], 0x0641 => [0xFED1, 4], 0x0642 => [0xFED5, 4],
        0x0643 => [0xFED9, 4], 0x0644 => [0xFEDD, 4], 0x0645 => [0xFEE1, 4], 0x0646 => [0xFEE5, 4],
        0x0647 => [0xFEE9, 4], 0x0648 => [0xFEED, 2], 0x0649 => [0xFEEF, 2], 0x064A => [0xFEF1, 4],
        // Persian letters seen in names.
        0x067E => [0xFB56, 4], 0x0686 => [0xFB7A, 4], 0x0698 => [0xFB8A, 2], 0x06A9 => [0xFB8E, 4],
        0x06AF => [0xFB92, 4], 0x06CC => [0xFBFC, 4],
    ];

    /** Lam followed by these alefs becomes one glyph: [isolated, final]. */
    private const LAM_ALEF = [
        0x0622 => [0xFEF5, 0xFEF6], 0x0623 => [0xFEF7, 0xFEF8],
        0x0625 => [0xFEF9, 0xFEFA], 0x0627 => [0xFEFB, 0xFEFC],
    ];

    private const TATWEEL = 0x0640;

    private const LAM = 0x0644;

    /** Brackets swap when they sit inside right-to-left text. */
    private const MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{', '&lt;' => '&gt;', '&gt;' => '&lt;', '<' => '>', '>' => '<'];

    /**
     * Shape every Arabic text node of an HTML document. Markup, styles and the
     * document head are left alone.
     */
    public static function html(string $html): string
    {
        if (! self::hasArabic($html)) {
            return $html;
        }

        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $skip = 0;
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] === '<') {
                if (preg_match('#^<\s*(/?)\s*(style|script|title|head)\b#i', $part, $m) === 1) {
                    $skip += $m[1] === '/' ? -1 : 1;
                    $skip = max(0, $skip);
                }

                continue;
            }
            if ($skip === 0 && self::hasArabic($part)) {
                $parts[$i] = self::node($part);
            }
        }

        return implode('', $parts);
    }

    /**
     * One text node: keep the whitespace around it, split a long Arabic text
     * into lines in reading order, then shape and reorder each line.
     */
    private static function node(string $text): string
    {
        if (preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $m) !== 1) {
            return $text;
        }
        [, $lead, $core, $trail] = $m;

        $lines = self::baseIsRtl($core) ? self::wrap($core) : [$core];

        return $lead . implode('<br>', array_map(self::visual(...), $lines)) . $trail;
    }

    /** Plain text in, shaped text in visual (left-to-right) order out. */
    public static function visual(string $text): string
    {
        if (! self::hasArabic($text)) {
            return $text;
        }

        $tokens = self::tokens(self::shape($text));
        $count = count($tokens);
        if ($count === 0) {
            return $text;
        }

        // Strong direction of each token: R (Arabic), L (Latin letters, digits) or N (neutral).
        $types = array_map(self::typeOf(...), $tokens);
        $rtl = self::baseIsRtl($text);

        // A neutral between two tokens of the same direction takes it; any
        // other neutral takes the direction of the text as a whole.
        for ($i = 0; $i < $count; $i++) {
            if ($types[$i] !== 'N') {
                continue;
            }
            $before = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($types[$j] !== 'N') {
                    $before = $types[$j];
                    break;
                }
            }
            $after = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($types[$j] !== 'N') {
                    $after = $types[$j];
                    break;
                }
            }
            $types[$i] = $before !== null && $before === $after ? $before : ($rtl ? 'R' : 'L');
        }

        // Embedding levels, then reverse runs from the highest level down.
        $levels = array_map(fn (string $t): int => $rtl ? ($t === 'R' ? 1 : 2) : ($t === 'R' ? 1 : 0), $types);
        foreach ($tokens as $i => $token) {
            if ($levels[$i] % 2 === 1 && isset(self::MIRROR[$token])) {
                $tokens[$i] = self::MIRROR[$token];
            }
        }
        for ($level = max($levels); $level >= 1; $level--) {
            $i = 0;
            while ($i < $count) {
                if ($levels[$i] < $level) {
                    $i++;

                    continue;
                }
                $start = $i;
                while ($i < $count && $levels[$i] >= $level) {
                    $i++;
                }
                $run = array_reverse(array_slice($tokens, $start, $i - $start));
                array_splice($tokens, $start, $i - $start, $run);
                $runLevels = array_reverse(array_slice($levels, $start, $i - $start));
                array_splice($levels, $start, $i - $start, $runLevels);
            }
        }

        return implode('', $tokens);
    }

    /** Join the Arabic letters of a text (logical order kept). Vowel marks are dropped. */
    public static function shape(string $text): string
    {
        $chars = mb_str_split((string) preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text));
        $codes = array_map(mb_ord(...), $chars);
        $count = count($codes);
        $out = '';

        for ($i = 0; $i < $count; $i++) {
            $code = $codes[$i];
            if (! isset(self::FORMS[$code])) {
                $out .= $chars[$i];

                continue;
            }

            $joinsBefore = $i > 0 && self::joinsForward($codes[$i - 1]);
            $next = $codes[$i + 1] ?? null;

            if ($code === self::LAM && $next !== null && isset(self::LAM_ALEF[$next])) {
                $out .= mb_chr(self::LAM_ALEF[$next][$joinsBefore ? 1 : 0]);
                $i++;

                continue;
            }

            [$first, $forms] = self::FORMS[$code];
            $joinsAfter = $forms === 4 && $next !== null && self::joinsBackward($next);

            $offset = match (true) {
                $forms === 1 => 0,
                $joinsBefore && $joinsAfter => 3,
                $joinsAfter => 2,
                $joinsBefore => 1,
                default => 0,
            };
            $out .= mb_chr($first + $offset);
        }

        return $out;
    }

    public static function hasArabic(string $text): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text) === 1;
    }

    /** A letter the NEXT letter can join on to. */
    private static function joinsForward(int $code): bool
    {
        return $code === self::TATWEEL || (isset(self::FORMS[$code]) && self::FORMS[$code][1] === 4);
    }

    /** A letter that joins on to the one BEFORE it. */
    private static function joinsBackward(int $code): bool
    {
        return $code === self::TATWEEL || (isset(self::FORMS[$code]) && self::FORMS[$code][1] > 1);
    }

    /** Right-to-left when the first letter is Arabic. */
    private static function baseIsRtl(string $text): bool
    {
        return preg_match('/[\p{L}]/u', $text, $m) === 1 && self::hasArabic($m[0]);
    }

    /**
     * Characters, with each HTML entity (&amp;) kept whole.
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        preg_match_all('/&#?\w+;|./su', $text, $m);

        return $m[0];
    }

    private static function typeOf(string $token): string
    {
        if (strlen($token) > 1 && $token[0] === '&') {
            return 'N';
        }
        if (self::hasArabic($token) && preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $token) !== 1) {
            return 'R';
        }

        return preg_match('/[\p{L}\p{N}]/u', $token) === 1 ? 'L' : 'N';
    }

    /**
     * Break a long text on spaces into lines of at most LINE_CHARS characters,
     * in reading order. A single word longer than that stays whole.
     *
     * @return list<string>
     */
    private static function wrap(string $text): array
    {
        if (mb_strlen($text) <= self::LINE_CHARS) {
            return [$text];
        }

        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            if ($line !== '' && mb_strlen($line . ' ' . $word) > self::LINE_CHARS) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $line === '' ? $word : $line . ' ' . $word;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }
}

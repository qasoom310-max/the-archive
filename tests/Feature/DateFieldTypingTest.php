<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * What the shared date box makes of a date somebody types.
 *
 * `<x-date-field>` accepts a date typed day-first and forgives the separator,
 * then writes the ISO value Livewire is bound to. The year was matched as
 * `(\d{2}|\d{4})`, and alternation takes the FIRST branch that matches — so
 * the two-digit branch ate the "20" of "2026" and left the "26" unread. Every
 * part after the year is optional, so the match still succeeded: a four-digit
 * year was saved as 20xx and the time was dropped. The office typed 2026 into
 * a trip dated 2020, saw 2020 come back, and reported that the date would not
 * change.
 *
 * There is no JS test runner in this project, so the pattern is lifted out of
 * the source and exercised here. PCRE and JavaScript agree on ordered
 * alternation and on every construct used in it, so this tests the behaviour
 * rather than the spelling — swap the branches back and these fail.
 */
final class DateFieldTypingTest extends TestCase
{
    /** The live pattern, read from the component rather than copied here. */
    private function pattern(): string
    {
        $js = (string) file_get_contents(base_path('resources/js/app.js'));

        $at = strpos($js, 'typed.match(/');
        $this->assertNotFalse($at, 'The date box no longer parses what is typed — re-point this guard.');

        $from = $at + strlen('typed.match(/');
        $to = strpos($js, '/);', $from);
        $this->assertNotFalse($to);

        return '#'.substr($js, $from, $to - $from).'#';
    }

    /**
     * @return array{0: int, 1: string}  the year, and the time or ''
     */
    private function read(string $typed): array
    {
        preg_match($this->pattern(), $typed, $m);

        $this->assertNotSame([], $m, "Not understood at all: {$typed}");

        $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
        $time = isset($m[4], $m[5]) ? $m[4].':'.$m[5] : '';

        return [$year, $time];
    }

    public function test_a_four_digit_year_is_read_whole(): void
    {
        [$year] = $this->read('25/09/2026');

        $this->assertSame(2026, $year, 'A typed 2026 became 20xx — the year branches are the wrong way round.');
    }

    public function test_a_four_digit_year_does_not_swallow_the_time(): void
    {
        [$year, $time] = $this->read('25/09/2026 09:20');

        $this->assertSame(2026, $year);
        $this->assertSame('09:20', $time, 'The time was dropped along with half the year.');
    }

    /** Two digits still mean this century, which is how most are typed. */
    public function test_a_two_digit_year_still_works(): void
    {
        $this->assertSame([2026, '09:20'], $this->read('25/09/26 09:20'));
        $this->assertSame([2026, ''], $this->read('31.8.26'));
    }

    /** Every separator the box promises to forgive, at four digits. */
    public function test_each_separator_is_forgiven_with_a_full_year(): void
    {
        foreach (['25/09/2026', '25-09-2026', '25.09.2026', '25 09 2026'] as $typed) {
            [$year] = $this->read($typed);
            $this->assertSame(2026, $year, "Not read as 2026: {$typed}");
        }
    }

    /** A single-figure day and month, which is how a person types January. */
    public function test_a_short_day_and_month_keep_their_full_year(): void
    {
        [$year] = $this->read('1/1/2026');

        $this->assertSame(2026, $year);
    }

    /** A date genuinely in 2020 is still read as 2020. */
    public function test_a_real_two_digit_twenty_is_left_alone(): void
    {
        $this->assertSame([2020, ''], $this->read('25/09/20'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Pdf\ArabicText;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

/**
 * DomPDF can't join Arabic letters or lay them out right-to-left, so an Arabic
 * customer name printed as loose letters in the wrong order. Every PDF now
 * goes through ArabicText first.
 */
final class ArabicPdfTextTest extends TestCase
{
    /** @return list<string> the code points of a string, as hex */
    private function codes(string $text): array
    {
        return array_map(fn (string $c): string => strtoupper(dechex(mb_ord($c))), mb_str_split($text));
    }

    public function test_letters_are_joined_and_written_right_to_left(): void
    {
        // س ل ا م → isolated meem, lam-alef (final, joined to seen), initial seen.
        $this->assertSame(['FEE1', 'FEFC', 'FEB3'], $this->codes(ArabicText::visual('سلام')));
    }

    public function test_numbers_inside_arabic_keep_reading_left_to_right(): void
    {
        $this->assertSame(['31', '32', '33', '20', 'FEAA', 'FEE4', 'FEA4', 'FEE3'], $this->codes(ArabicText::visual('محمد 123')));
    }

    public function test_brackets_mirror_inside_arabic(): void
    {
        $this->assertStringStartsWith('(BD 5) ', ArabicText::visual('علي (BD 5)'));
    }

    public function test_a_long_name_is_split_into_lines_in_reading_order(): void
    {
        $html = ArabicText::html('<td>شركة ثامر عبدالقادر الخريجي للدعاية والاعلان</td>');

        [$first, $second] = explode('<br>', strip_tags($html, '<br>'));
        // The first line holds the START of the name ("شركة"), the second its end.
        $this->assertStringEndsWith(ArabicText::visual('شركة'), $first);
        $this->assertStringEndsWith(ArabicText::visual('الخريجي'), $second);
    }

    public function test_english_markup_and_styles_are_left_alone(): void
    {
        $html = '<style>.a{content:"ب"}</style><p class="x">Phone +966 59 &amp; more</p>';

        $this->assertSame($html, ArabicText::html($html));
    }

    public function test_every_pdf_gets_the_arabic_shaped(): void
    {
        $pdf = Pdf::loadHTML('<html><body><p>محمد</p></body></html>');

        $text = $pdf->getDomPDF()->getDom()->textContent;
        $this->assertStringContainsString(ArabicText::visual('محمد'), $text);
        $this->assertStringNotContainsString('محمد', $text);
    }
}

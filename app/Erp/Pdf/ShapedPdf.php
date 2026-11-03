<?php

declare(strict_types=1);

namespace App\Erp\Pdf;

use Barryvdh\DomPDF\PDF;

/**
 * The PDF wrapper every `Pdf::loadView()` / `Pdf::loadHTML()` gets (bound in
 * AppServiceProvider). It shapes Arabic text before DomPDF sees the HTML, so
 * an Arabic name prints joined and right-to-left on every document.
 */
class ShapedPdf extends PDF
{
    public function loadHTML(string $string, ?string $encoding = null): self
    {
        parent::loadHTML(ArabicText::html($string), $encoding);

        return $this;
    }
}

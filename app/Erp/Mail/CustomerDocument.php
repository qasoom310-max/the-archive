<?php

declare(strict_types=1);

namespace App\Erp\Mail;

/**
 * One email to a customer about one document: what to say and what to attach.
 * Built by each app's sender (Limousine, Rent A Car) and posted by
 * {@see CustomerMailer}, which adds the logo and the terms PDF.
 */
final readonly class CustomerDocument
{
    /**
     * @param  list<string>                              $paragraphs   body text, in order
     * @param  list<array{0: string, 1: string}>         $facts        label / value rows in the summary box
     * @param  list<array{data: string, name: string}>   $attachments  PDFs (raw bytes + file name)
     */
    public function __construct(
        public string $subject,
        public string $heading,
        public string $customerName,
        public array $paragraphs,
        public array $facts,
        public array $attachments,
        /** "Quotation QT/01695" — for the activity log. */
        public string $label,
    ) {}
}

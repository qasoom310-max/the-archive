<?php

declare(strict_types=1);

namespace App\Erp\Mail;

/**
 * One email to a customer: what to say, what to show and what to attach.
 * Its wording comes from {@see EmailTemplates::compose()}; every customer
 * email is drawn by the one layout, emails/customer-document.blade.php.
 */
final readonly class CustomerDocument
{
    /**
     * @param  list<string>                                    $paragraphs   text above the summary box, in order
     * @param  list<array{0: string, 1: string}>               $facts        label / value rows in the summary box
     * @param  list<array{data: string, name: string}>         $attachments  PDFs (raw bytes + file name)
     * @param  list<string>                                    $outro        text below the summary box
     * @param  array{title: string, lines: list<string>}|null  $callout      one figure to stand out (a coupon code)
     * @param  array{label: string, url: string}|null          $button       one thing to press (sign the service order)
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
        /** False for a note that is not a sale (the driver details), so the terms PDF is left off. */
        public bool $withTerms = true,
        public array $outro = [],
        public ?array $callout = null,
        public ?array $button = null,
    ) {}

    /**
     * Build one from a template: the wording is the database's, the facts,
     * attachments and the rest are the caller's.
     *
     * @param  array<string, string>                           $vars
     * @param  list<array{0: string, 1: string}>               $facts
     * @param  list<array{data: string, name: string}>         $attachments
     * @param  array{title: string, lines: list<string>}|null  $callout
     */
    public static function fromTemplate(
        string $type,
        string $customerName,
        array $vars,
        array $facts,
        array $attachments,
        string $label,
        bool $withTerms = true,
        ?array $callout = null,
        ?string $buttonUrl = null,
    ): self {
        $mail = app(EmailTemplates::class)->compose($type, ['customer' => $customerName, ...$vars]);

        return new self(
            subject: $mail['subject'],
            heading: $mail['heading'],
            customerName: $customerName,
            paragraphs: $mail['intro'],
            facts: $facts,
            attachments: $attachments,
            label: $label,
            withTerms: $withTerms,
            outro: $mail['outro'],
            callout: $callout,
            button: $buttonUrl !== null && $buttonUrl !== '' ? ['label' => $mail['button'] !== '' ? $mail['button'] : 'Open', 'url' => $buttonUrl] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace Modules\Limousine\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Limousine\Models\LimoQuotation;

/**
 * The quotation, sent to the customer with the PDF attached.
 *
 * An attachment rather than a link: a quote is something the customer forwards
 * to whoever approves it, prints, or comes back to next week. A page that
 * expires would be the wrong shape for that.
 *
 * `$withoutTotal` has to reach the BODY as well as the attachment: a PDF with
 * no total, under an e-mail that summarises one, hands the figure over anyway.
 */
final class QuotationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public LimoQuotation $quote,
        public string $companyName,
        public float $total,
        /** Raw PDF bytes, attached as the quotation. */
        public string $pdf,
        public string $filename,
        public bool $withoutTotal = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->companyName . ' — ' . __('Quotation') . ' ' . (string) $this->quote->reference,
        );
    }

    public function content(): Content
    {
        // markdown, not view — the template uses <x-mail::message>/<x-mail::panel>,
        // which only resolve through Laravel's Markdown mail renderer. Wired as
        // `view` this 500s in production with "No hint path defined for [mail]"
        // (Mail::fake() in tests never renders the body, so it went unnoticed).
        return new Content(
            markdown: 'limousine::quotation-email',
            with: [
                'quote' => $this->quote,
                'companyName' => $this->companyName,
                'total' => $this->total,
                'withoutTotal' => $this->withoutTotal,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}

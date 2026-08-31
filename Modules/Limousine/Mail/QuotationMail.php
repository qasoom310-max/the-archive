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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->companyName . ' — ' . __('Quotation') . ' ' . (string) $this->quote->reference,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'limousine::quotation-email',
            with: [
                'quote' => $this->quote,
                'companyName' => $this->companyName,
                'total' => $this->total,
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

<?php

declare(strict_types=1);

namespace Modules\Limousine\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Limousine\Models\LimoReceipt;

/**
 * The customer's receipt, attached.
 *
 * An attachment rather than a link, like the coupon voucher: there is nothing
 * for the customer to DO with it. It is proof they paid, so it wants to be a
 * file they keep — in their mail, on their phone — not a page that expires.
 */
final class ReceiptMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public LimoReceipt $receipt,
        public string $companyName,
        /** Raw PDF bytes, attached as the receipt. */
        public string $pdf,
        public string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->companyName . ' — ' . __('Receipt') . ' ' . (string) $this->receipt->reference,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'limousine::receipt-email',
            with: [
                'receipt' => $this->receipt,
                'companyName' => $this->companyName,
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

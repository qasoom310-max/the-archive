<?php

declare(strict_types=1);

namespace Modules\Limousine\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Limousine\Models\LimoCoupon;

/**
 * The customer's refund coupon, with the voucher attached.
 *
 * An attachment rather than a link, unlike the Service Order: there is nothing
 * for the customer to DO here. It is a receipt for money they paid on a trip
 * that did not run, so it wants to be a file they keep — in their mail, on
 * their phone — not a page that expires.
 *
 * The code is in the body as well as the PDF, because most people will quote it
 * off the screen without opening the attachment at all.
 */
final class CouponVoucherMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public LimoCoupon $coupon,
        public string $companyName,
        public float $remaining,
        /** Raw PDF bytes, attached as the voucher. */
        public string $pdf,
        public string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->companyName . ' — ' . __('Your credit coupon') . ' ' . (string) $this->coupon->code,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'limousine::coupon-voucher-email',
            with: [
                'coupon' => $this->coupon,
                'companyName' => $this->companyName,
                'remaining' => $this->remaining,
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

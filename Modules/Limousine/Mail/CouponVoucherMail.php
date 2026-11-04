<?php

declare(strict_types=1);

namespace Modules\Limousine\Mail;

use App\Erp\Mail\CustomerDocument;
use App\Erp\Views\ValueFormat;
use App\Mail\Concerns\UsesCustomerLayout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Limousine\Models\LimoCoupon;

/**
 * The credit coupon, sent to the customer. No button and no link: the code IS
 * the thing, so it stands out in the body where it can be read without
 * opening the attachment, and the voucher rides along as a PDF to keep. The
 * wording is the "Credit coupon" template on Settings → Customer emails.
 */
final class CouponVoucherMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesCustomerLayout;

    public function __construct(
        public LimoCoupon $coupon,
        public string $companyName,
        public float $remaining,
        /** Raw PDF bytes, attached as the voucher. */
        public string $pdf,
        public string $filename,
    ) {
        $this->locale('en');
    }

    public function document(): CustomerDocument
    {
        $code = (string) $this->coupon->code;
        $value = ValueFormat::money($this->remaining);
        $until = $this->coupon->expires_at?->isoFormat('DD-MMM-YYYY') ?? '';

        $lines = ['Value remaining: ' . $value];
        if ($until !== '') {
            $lines[] = 'Valid until ' . $until;
        }

        return CustomerDocument::fromTemplate(
            type: 'coupon',
            customerName: (string) ($this->coupon->customer->name ?? ''),
            vars: ['code' => $code, 'value' => $value, 'valid_until' => $until],
            facts: [],
            attachments: [],
            label: 'Coupon ' . $code,
            callout: ['title' => $code, 'lines' => $lines],
        );
    }

    public function envelope(): Envelope
    {
        return $this->customerEnvelope($this->document()->subject);
    }

    public function content(): Content
    {
        return $this->customerContent($this->document(), [$this->filename]);
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

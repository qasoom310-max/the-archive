<?php

declare(strict_types=1);

namespace Modules\Rental\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\Rental\Models\RentalOrder;

/**
 * The Car Hire Agreement emailed to a customer, with the PDF attached.
 */
final class RentalAgreementMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $pdf,
        public RentalOrder $order,
        public string $companyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->companyName . ' — ' . __('Car Hire Agreement') . ' — ' . (string) $this->order->reference,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'rental::agreement-email',
            with: ['order' => $this->order, 'companyName' => $this->companyName],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $name = 'agreement-' . Str::slug((string) $this->order->reference) . '.pdf';

        return [
            Attachment::fromData(fn (): string => $this->pdf, $name)->withMime('application/pdf'),
        ];
    }
}

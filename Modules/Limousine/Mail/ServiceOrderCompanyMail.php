<?php

declare(strict_types=1);

namespace Modules\Limousine\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Limousine\Models\LimoLeg;

/**
 * Tells a CORPORATE customer their guest has been collected.
 *
 * No signing link: the company was not in the car and cannot sign for a journey
 * it did not take — it needs to know the job was done, not to attest to it. So
 * this is a notice, sent to the company's service address rather than whoever
 * placed the booking.
 */
final class ServiceOrderCompanyMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public LimoLeg $leg,
        public string $companyName,
        public string $customerName,
        /** @var array<string, string> */
        public array $details = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->companyName . ' — ' . __('Service completed') . ' — ' . (string) $this->leg->reference,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'limousine::service-order-company-email',
            with: [
                'leg' => $this->leg,
                'companyName' => $this->companyName,
                'customerName' => $this->customerName,
                'details' => $this->details,
            ],
        );
    }
}

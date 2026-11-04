<?php

declare(strict_types=1);

namespace Modules\Limousine\Mail;

use App\Erp\Mail\CustomerDocument;
use App\Mail\Concerns\UsesCustomerLayout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * Asks a private customer to sign the service order for their trip. The
 * button is the point; the link is repeated under it for mail programs that
 * strip buttons. The wording is the "Service order to sign" template on
 * Settings → Customer emails.
 */
final class ServiceOrderMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesCustomerLayout;

    /**
     * @param  array<string, string>  $details
     */
    public function __construct(
        public LimoLeg $leg,
        public string $signUrl,
        public string $companyName,
        public array $details = [],
    ) {
        $this->locale('en');
    }

    public function document(): CustomerDocument
    {
        $reference = (string) $this->leg->reference;
        $booking = $this->leg->legable instanceof LimoBooking ? $this->leg->legable : null;

        return CustomerDocument::fromTemplate(
            type: 'service_order',
            customerName: (string) ($booking?->customer->name ?? ''),
            vars: ['reference' => $reference],
            facts: array_map(static fn (string $label, string $value): array => [$label, $value], array_keys($this->details), array_values($this->details)),
            attachments: [],
            label: 'Service order ' . $reference,
            buttonUrl: $this->signUrl,
        );
    }

    public function envelope(): Envelope
    {
        return $this->customerEnvelope($this->document()->subject);
    }

    public function content(): Content
    {
        return $this->customerContent($this->document(), []);
    }
}

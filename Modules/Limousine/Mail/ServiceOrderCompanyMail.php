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
use Modules\Limousine\Models\LimoLeg;

/**
 * Tells a company customer the trip is done. No signing button: the company
 * was not in the car, so it is being informed, not asked to attest. The
 * wording is the "Service completed (company)" template on Settings →
 * Customer emails.
 */
final class ServiceOrderCompanyMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesCustomerLayout;

    /**
     * @param  array<string, string>  $details
     */
    public function __construct(
        public LimoLeg $leg,
        public string $companyName,
        public string $customerName,
        public array $details = [],
    ) {
        $this->locale('en');
    }

    public function document(): CustomerDocument
    {
        $reference = (string) $this->leg->reference;

        return CustomerDocument::fromTemplate(
            type: 'service_order_company',
            customerName: $this->customerName,
            vars: ['reference' => $reference],
            facts: array_map(static fn (string $label, string $value): array => [$label, $value], array_keys($this->details), array_values($this->details)),
            attachments: [],
            label: 'Service order ' . $reference,
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

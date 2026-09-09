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
 * The Service Order signing invitation. Carries a LINK rather than a PDF
 * attachment: the point is for the customer to open it and sign, and a link is
 * what a phone can act on. The signed copy is downloadable from that page once
 * they are done.
 */
final class ServiceOrderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public LimoLeg $leg,
        public string $signUrl,
        public string $companyName,
        /** @var array<string, string> */
        public array $details = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->companyName . ' — ' . __('Service Order') . ' ' . (string) $this->leg->reference,
        );
    }

    public function content(): Content
    {
        // markdown, not view — see QuotationMail::content() for why: this
        // template's <x-mail::message>/<x-mail::table>/<x-mail::button> only
        // resolve through Laravel's Markdown mail renderer.
        return new Content(
            markdown: 'limousine::service-order-email',
            with: [
                'leg' => $this->leg,
                'signUrl' => $this->signUrl,
                'companyName' => $this->companyName,
                'details' => $this->details,
            ],
        );
    }
}

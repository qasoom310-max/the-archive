<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Erp\Mail\CustomerDocument;
use App\Erp\Mail\CustomerMailer;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * For a Mailable that goes to a customer: drawn by the one customer layout
 * (emails/customer-document), sent from the database's own address, with the
 * wording, signature and footer set on Settings → Customer emails.
 */
trait UsesCustomerLayout
{
    protected function customerEnvelope(string $subject): Envelope
    {
        $mailer = app(CustomerMailer::class);
        $from = $mailer->fromAddress();
        $name = $mailer->companyName();

        return new Envelope(
            from: $from !== null ? new Address($from, $name) : null,
            replyTo: $from !== null ? [new Address($from, $name)] : [],
            subject: $subject,
        );
    }

    /**
     * @param  list<string>  $attachmentNames
     */
    protected function customerContent(CustomerDocument $document, array $attachmentNames): Content
    {
        $mailer = app(CustomerMailer::class);

        return new Content(
            view: 'emails.customer-document',
            with: [
                'document' => $document,
                'companyName' => $mailer->companyName(),
                'logo' => $mailer->logo(),
                'attachmentNames' => $attachmentNames,
            ],
        );
    }
}

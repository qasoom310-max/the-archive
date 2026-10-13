<?php

declare(strict_types=1);

namespace App\Mail;

use App\Erp\Mail\CustomerDocument;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A quotation, invoice or receipt emailed to the customer: the company logo
 * across the top, a short note, a summary box, and the PDFs attached.
 * Built by {@see \App\Erp\Mail\CustomerMailer}.
 */
final class CustomerDocumentMail extends Mailable
{
    /**
     * @param  list<array{data: string, name: string}>           $attachmentFiles
     * @param  array{data: string, mime: string, name: string}|null  $logo
     */
    public function __construct(
        public CustomerDocument $document,
        public array $attachmentFiles,
        public string $companyName,
        public ?string $fromAddress,
        public ?array $logo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromAddress !== null ? new Address($this->fromAddress, $this->companyName) : null,
            replyTo: $this->fromAddress !== null ? [new Address($this->fromAddress, $this->companyName)] : [],
            subject: $this->document->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-document',
            with: [
                'document' => $this->document,
                'companyName' => $this->companyName,
                'logo' => $this->logo,
                'attachmentNames' => array_map(static fn (array $file): string => $file['name'], $this->attachmentFiles),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            static fn (array $file): Attachment => Attachment::fromData(static fn (): string => $file['data'], $file['name'])
                ->withMime('application/pdf'),
            $this->attachmentFiles,
        );
    }
}

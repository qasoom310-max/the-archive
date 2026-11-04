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
use Modules\Limousine\Models\LimoQuotation;

/**
 * The quotation, sent from the quotations list with the PDF attached.
 *
 * An attachment rather than a link: a quote is something the customer forwards
 * to whoever approves it, prints, or comes back to next week. The wording is
 * the "Quotation" template on Settings → Customer emails.
 */
final class QuotationMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesCustomerLayout;

    public function __construct(
        public LimoQuotation $quote,
        public string $companyName,
        public float $total,
        /** Raw PDF bytes, attached as the quotation. */
        public string $pdf,
        public string $filename,
        public bool $withoutTotal = false,
    ) {
        // The wording is English (Settings → Customer emails), so the labels are too.
        $this->locale('en');
    }

    public function document(): CustomerDocument
    {
        $reference = (string) $this->quote->reference;
        $until = $this->quote->valid_until?->isoFormat('DD-MMM-YYYY') ?? '';

        // The body has to know about a total-free copy as well: an email that
        // summarises the total under a total-free attachment hands it over anyway.
        $facts = [['Quotation no.', $reference]];
        $facts[] = $this->withoutTotal
            ? ['Rates', 'Per journey, in the attached quotation']
            : ['Total', ValueFormat::money($this->total)];
        if ($until !== '') {
            $facts[] = ['Valid until', $until];
        }

        return CustomerDocument::fromTemplate(
            type: 'quotation',
            customerName: (string) ($this->quote->customer->name ?? ''),
            vars: [
                'reference' => $reference,
                'total' => $this->withoutTotal ? '' : ValueFormat::money($this->total),
                'valid_until' => $until,
            ],
            facts: $facts,
            attachments: [],
            label: 'Quotation ' . $reference,
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

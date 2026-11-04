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
use Modules\Limousine\Models\LimoReceipt;

/**
 * A receipt, sent from the receipts list with the PDF attached. The wording
 * is the "Payment receipt" template on Settings → Customer emails.
 */
final class ReceiptMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesCustomerLayout;

    public function __construct(
        public LimoReceipt $receipt,
        public string $companyName,
        /** Raw PDF bytes, attached as the receipt. */
        public string $pdf,
        public string $filename,
    ) {
        $this->locale('en');
    }

    public function document(): CustomerDocument
    {
        $reference = (string) $this->receipt->reference;
        $amount = ValueFormat::money((float) $this->receipt->amount);
        $balance = $this->receipt->balance_after !== null && (float) $this->receipt->balance_after > 0
            ? ValueFormat::money((float) $this->receipt->balance_after)
            : '';

        $facts = [['Receipt no.', $reference], ['Amount received', $amount]];
        if ($balance !== '') {
            $facts[] = ['Balance still to pay', $balance];
        }

        return CustomerDocument::fromTemplate(
            type: 'receipt',
            customerName: (string) ($this->receipt->customer->name ?? ''),
            vars: [
                'reference' => $reference,
                'amount' => $amount,
                'invoice' => (string) ($this->receipt->invoice->reference ?? ''),
                'balance' => $balance,
            ],
            facts: $facts,
            attachments: [],
            label: 'Receipt ' . $reference,
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

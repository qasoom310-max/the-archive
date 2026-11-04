<?php

declare(strict_types=1);

namespace Modules\Rental\Mail;

use App\Erp\Mail\CustomerDocument;
use App\Mail\Concerns\UsesCustomerLayout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\Rental\Models\RentalOrder;

/**
 * The Car Hire Agreement emailed to a customer, with the PDF attached. The
 * wording is the "Car hire agreement" template on Settings → Customer emails.
 */
final class RentalAgreementMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesCustomerLayout;

    public function __construct(
        public string $pdf,
        public RentalOrder $order,
        public string $companyName,
    ) {
        $this->locale('en');
    }

    public function document(): CustomerDocument
    {
        $reference = (string) $this->order->reference;
        $car = (string) ($this->order->vehicle?->displayName() ?? '');
        $pickup = $this->order->start_date?->format('d-m-Y') ?? '';
        $return = $this->order->end_date?->format('d-m-Y') ?? '';

        $facts = [['Agreement no.', $reference]];
        foreach ([['Car', $car], ['Pick-up date', $pickup], ['Return date', $return]] as [$label, $value]) {
            if ($value !== '') {
                $facts[] = [$label, $value];
            }
        }

        return CustomerDocument::fromTemplate(
            type: 'agreement',
            customerName: (string) ($this->order->customer->name ?? ''),
            vars: ['reference' => $reference, 'car' => $car, 'pickup_date' => $pickup, 'return_date' => $return],
            facts: $facts,
            attachments: [],
            label: 'Agreement ' . $reference,
        );
    }

    public function envelope(): Envelope
    {
        return $this->customerEnvelope($this->document()->subject);
    }

    public function content(): Content
    {
        return $this->customerContent($this->document(), [$this->filename()]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, $this->filename())->withMime('application/pdf'),
        ];
    }

    private function filename(): string
    {
        return 'agreement-' . Str::slug((string) $this->order->reference) . '.pdf';
    }
}

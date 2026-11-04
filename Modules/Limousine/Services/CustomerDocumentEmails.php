<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Mail\CustomerDocument;
use App\Erp\Mail\CustomerMailer;
use App\Erp\Money\Currencies;
use Illuminate\Support\Collection;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Rental\Models\Vehicle;

/**
 * Emails the customer each limousine document as it is made:
 *
 * - a new quotation → the quotation;
 * - a new invoice (a booking, or a quote turned into a bill) → the invoice,
 *   plus its receipts when part or all of it is already paid;
 * - money received later → the receipt, plus the invoice as it now stands.
 *
 * Only when Settings → "Email documents to customers" is on for this database,
 * only to a customer with an email address, and always after the response
 * ({@see CustomerMailer::later()}) so Save is never slowed down or broken by
 * the mail server. Imports never call this, so history is never mailed out.
 */
final class CustomerDocumentEmails
{
    public function __construct(private readonly CustomerMailer $mailer) {}

    public function quotationCreated(int $quotationId, bool $now = false): void
    {
        $this->mailer->later(function () use ($quotationId): void {
            $quote = LimoQuotation::query()->with('customer')->find($quotationId);
            if ($quote === null) {
                return;
            }

            $pdf = app(QuotationPdf::class);
            $reference = (string) $quote->reference;
            $facts = [[__('Quotation no.'), $reference]];
            if ($quote->quote_date !== null) {
                $facts[] = [__('Date'), $quote->quote_date->format('j M Y')];
            }
            if ($quote->valid_until !== null) {
                $facts[] = [__('Valid until'), $quote->valid_until->format('j M Y')];
            }
            if ((float) $quote->fare > 0) {
                $facts[] = [__('Total'), Currencies::format((float) $quote->fare)];
            }

            $this->mailer->send($this->addressOf($quote->customer), CustomerDocument::fromTemplate(
                type: 'quotation',
                customerName: (string) ($quote->customer->name ?? ''),
                vars: [
                    'reference' => $reference,
                    'total' => Currencies::format((float) $quote->fare),
                    'valid_until' => $quote->valid_until?->format('j M Y') ?? '',
                ],
                facts: $facts,
                attachments: [['data' => $pdf->render($quote), 'name' => $pdf->filename($quote)]],
                label: __('Quotation') . ' ' . $reference,
            ));
        }, $now);
    }

    public function invoiceIssued(int $invoiceId, bool $now = false): void
    {
        $this->mailer->later(function () use ($invoiceId): void {
            $invoice = LimoInvoice::query()->with(['customer', 'booking'])->find($invoiceId);
            if ($invoice === null) {
                return;
            }

            $receipts = $this->receiptsOf($invoice);
            $paid = (float) $invoice->amount_paid > 0.0005 || $receipts->isNotEmpty();

            $state = ! $paid ? 'unpaid' : ($invoice->balance() <= 0.0005 ? 'paid' : 'part');

            $this->sendInvoiceEmail($invoice, $receipts, $state);
        }, $now);
    }

    public function paymentReceived(int $receiptId, bool $now = false): void
    {
        $this->mailer->later(function () use ($receiptId): void {
            $receipt = LimoReceipt::query()->with(['customer', 'invoice'])->find($receiptId);
            if ($receipt === null) {
                return;
            }

            $invoice = $receipt->invoice;
            $receiptPdf = app(LimoReceiptPdf::class);
            $reference = (string) $receipt->reference;

            $attachments = [['data' => $receiptPdf->render($receipt), 'name' => $receiptPdf->filename($receipt)]];
            $facts = [
                [__('Receipt no.'), $reference],
                [__('Amount received'), Currencies::format((float) $receipt->amount)],
            ];

            if ($invoice !== null) {
                $invoicePdf = app(LimoInvoicePdf::class);
                $attachments[] = ['data' => $invoicePdf->render($invoice), 'name' => $invoicePdf->filename($invoice)];
                $facts[] = [__('Invoice no.'), (string) $invoice->reference];
                $facts[] = [__('Balance due'), Currencies::format(max(0.0, $invoice->balance()))];
            }

            $customer = $receipt->customer ?? $invoice?->customer;

            $this->mailer->send($this->addressOf($customer), CustomerDocument::fromTemplate(
                type: 'receipt',
                customerName: (string) ($customer->name ?? ''),
                vars: [
                    'reference' => $reference,
                    'amount' => Currencies::format((float) $receipt->amount),
                    'invoice' => (string) ($invoice->reference ?? ''),
                    'balance' => $invoice !== null ? Currencies::format(max(0.0, $invoice->balance())) : '',
                ],
                facts: $facts,
                attachments: $attachments,
                label: __('Receipt') . ' ' . $reference,
            ));
        }, $now);
    }

    /**
     * A car and a driver were put on a trip: tell the customer who is coming,
     * how to reach them and which car to look for. Sent to the customer's
     * service email (else their general one), with no attachments.
     */
    public function driverAssigned(int $legId, bool $now = false): void
    {
        $this->mailer->later(function () use ($legId): void {
            $leg = LimoLeg::query()->with('legable.customer')->find($legId);
            $booking = $leg?->legable;
            if ($leg === null || ! $booking instanceof LimoBooking || ! $leg->hasCarAndDriver()) {
                return;
            }

            $car = Vehicle::query()->find($leg->car_id);
            $driver = LimoDriver::query()->find($leg->driver_id);
            if ($car === null || $driver === null) {
                return;
            }

            $customer = $booking->customer;
            $passenger = trim((string) $booking->pax_name);
            $reference = (string) $leg->reference;
            $carName = trim((string) $car->name);
            $color = trim((string) $car->color);

            $facts = [[__('Trip no.'), $reference]];
            if ($leg->start_at !== null) {
                $facts[] = [__('Pick-up'), $leg->start_at->format('D j M Y, H:i')];
            }
            foreach ([[__('Pick up'), $leg->from_location], [__('Drop off'), $leg->to_location]] as [$label, $place]) {
                if (trim((string) $place) !== '') {
                    $facts[] = [$label, trim((string) $place)];
                }
            }
            if ($passenger !== '' && $passenger !== trim((string) ($customer->name ?? ''))) {
                $facts[] = [__('Passenger'), $passenger];
            }
            $facts[] = [__('Driver'), trim((string) $driver->name)];
            if (trim((string) $driver->phone) !== '') {
                $facts[] = [__("Driver's phone"), trim((string) $driver->phone)];
            }
            $facts[] = [__('Car'), $color !== '' ? $carName . ' (' . $color . ')' : $carName];
            if (trim((string) $car->plate_no) !== '') {
                $facts[] = [__('Plate no.'), trim((string) $car->plate_no)];
            }

            $this->mailer->send($customer?->serviceEmail(), CustomerDocument::fromTemplate(
                type: 'driver_assigned',
                customerName: (string) ($customer->name ?? ''),
                vars: [
                    'reference' => $reference,
                    'pickup_time' => $leg->start_at?->format('D j M Y, H:i') ?? '',
                    'driver' => trim((string) $driver->name),
                    'driver_phone' => trim((string) $driver->phone),
                    'car' => $color !== '' ? $carName . ' (' . $color . ')' : $carName,
                    'plate' => trim((string) $car->plate_no),
                ],
                facts: $facts,
                attachments: [],
                label: __('Trip') . ' ' . $reference,
                withTerms: false,
            ));
        }, $now);
    }

    /**
     * @param  Collection<int, LimoReceipt>  $receipts
     * @param  'paid'|'part'|'unpaid'        $state
     */
    private function sendInvoiceEmail(LimoInvoice $invoice, Collection $receipts, string $state): void
    {
        $invoicePdf = app(LimoInvoicePdf::class);
        $receiptPdf = app(LimoReceiptPdf::class);
        $reference = (string) $invoice->reference;

        $attachments = [['data' => $invoicePdf->render($invoice), 'name' => $invoicePdf->filename($invoice)]];
        foreach ($receipts as $receipt) {
            $attachments[] = ['data' => $receiptPdf->render($receipt), 'name' => $receiptPdf->filename($receipt)];
        }

        $facts = [[__('Invoice no.'), $reference]];
        if ($invoice->booking !== null) {
            $facts[] = [__('Booking'), (string) $invoice->booking->reference];
        }
        $facts[] = [__('Total'), Currencies::format((float) $invoice->total)];
        $facts[] = [__('Paid'), Currencies::format((float) $invoice->amount_paid)];
        $facts[] = [__('Balance due'), Currencies::format(max(0.0, $invoice->balance()))];

        $this->mailer->send($this->addressOf($invoice->customer), CustomerDocument::fromTemplate(
            type: 'invoice',
            customerName: (string) ($invoice->customer->name ?? ''),
            vars: [
                'reference' => $reference,
                'total' => Currencies::format((float) $invoice->total),
                'paid' => Currencies::format((float) $invoice->amount_paid),
                'balance' => Currencies::format(max(0.0, $invoice->balance())),
                'payment_state' => $state,
            ],
            facts: $facts,
            attachments: $attachments,
            label: __('Invoice') . ' ' . $reference,
        ));
    }

    /**
     * The receipts behind an invoice: those written against it, and those
     * written against its booking (an advance taken with the booking).
     *
     * @return Collection<int, LimoReceipt>
     */
    private function receiptsOf(LimoInvoice $invoice): Collection
    {
        return LimoReceipt::query()
            ->where(function ($q) use ($invoice): void {
                $q->where('invoice_id', $invoice->id);
                if ($invoice->booking_id !== null) {
                    $q->orWhere('booking_id', $invoice->booking_id);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /** Billing goes to the customer's general email, else their service email. */
    private function addressOf(?LimoCustomer $customer): ?string
    {
        if ($customer === null) {
            return null;
        }

        $email = trim((string) $customer->email);

        return $email !== '' ? $email : $customer->serviceEmail();
    }
}

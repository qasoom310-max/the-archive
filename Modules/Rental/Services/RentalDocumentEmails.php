<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Mail\CustomerDocument;
use App\Erp\Mail\CustomerMailer;
use App\Erp\Money\Currencies;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\RentalReceipt;

/**
 * Emails the customer each Rent A Car document as it is made — the twin of
 * the Limousine sender ({@see \Modules\Limousine\Services\CustomerDocumentEmails}):
 *
 * - a new quotation → the quotation;
 * - a new invoice → the invoice, plus its receipts when part or all of it is
 *   already paid;
 * - a new receipt → the receipt, plus the invoice as it now stands.
 *
 * Only when Settings → "Email documents to customers" is on, only to a
 * customer with an email address, and after the response.
 */
final class RentalDocumentEmails
{
    public function __construct(private readonly CustomerMailer $mailer) {}

    public function quotationCreated(int $quotationId): void
    {
        $this->mailer->later(function () use ($quotationId): void {
            $quote = RentalQuotation::query()->with(['customer', 'vehicle'])->find($quotationId);
            if ($quote === null) {
                return;
            }

            $pdf = app(RentalQuotationPdf::class);
            $reference = (string) $quote->reference;

            $facts = [[__('Quotation no.'), $reference]];
            if ($quote->start_date !== null && $quote->end_date !== null) {
                $facts[] = [__('Rental period'), $quote->start_date->format('j M Y') . ' – ' . $quote->end_date->format('j M Y')];
            }
            if ($quote->valid_until !== null) {
                $facts[] = [__('Valid until'), $quote->valid_until->format('j M Y')];
            }
            $facts[] = [__('Total'), Currencies::format((float) $quote->total)];

            $this->mailer->send($quote->customer?->email, new CustomerDocument(
                subject: $this->mailer->companyName() . ' — ' . __('Quotation') . ' ' . $reference,
                heading: __('Your quotation :reference', ['reference' => $reference]),
                customerName: (string) ($quote->customer->name ?? ''),
                paragraphs: [
                    __('Thank you for your interest in our services. Please find your quotation attached.'),
                    __('To confirm the booking, simply reply to this email or call us, and we will take care of the rest.'),
                ],
                facts: $facts,
                attachments: [['data' => $pdf->render($quote), 'name' => $pdf->filename($quote)]],
                label: __('Quotation') . ' ' . $reference,
            ));
        });
    }

    public function invoiceIssued(int $invoiceId): void
    {
        $this->mailer->later(function () use ($invoiceId): void {
            $invoice = RentalInvoice::query()->with(['customer', 'receipts'])->find($invoiceId);
            if ($invoice === null) {
                return;
            }

            $invoicePdf = app(RentalInvoicePdf::class);
            $receiptPdf = app(RentalReceiptPdf::class);
            $reference = (string) $invoice->reference;
            $receipts = $invoice->receipts->sortBy('id');

            $attachments = [['data' => $invoicePdf->render($invoice), 'name' => $invoicePdf->filename($invoice)]];
            foreach ($receipts as $receipt) {
                $attachments[] = ['data' => $receiptPdf->render($receipt), 'name' => $receiptPdf->filename($receipt)];
            }

            $paragraphs = [__('Thank you for choosing us. Please find your invoice attached.')];
            if ($receipts->isNotEmpty() && $invoice->balance() <= 0.0005) {
                $paragraphs[] = __('This invoice is paid in full — your receipt is attached as well.');
            } elseif ($receipts->isNotEmpty()) {
                $paragraphs[] = __('We have received part of the payment — your receipt is attached. The remaining balance is shown below.');
            } else {
                $paragraphs[] = __('The amount due is shown below. Payment details are printed on the invoice.');
            }

            $this->mailer->send($invoice->customer?->email, new CustomerDocument(
                subject: $this->mailer->companyName() . ' — ' . __('Invoice') . ' ' . $reference,
                heading: __('Your invoice :reference', ['reference' => $reference]),
                customerName: (string) ($invoice->customer->name ?? ''),
                paragraphs: $paragraphs,
                facts: [
                    [__('Invoice no.'), $reference],
                    [__('Total'), Currencies::format((float) $invoice->total)],
                    [__('Paid'), Currencies::format((float) $invoice->amount_paid)],
                    [__('Balance due'), Currencies::format(max(0.0, $invoice->balance()))],
                ],
                attachments: $attachments,
                label: __('Invoice') . ' ' . $reference,
            ));
        });
    }

    public function paymentReceived(int $receiptId): void
    {
        $this->mailer->later(function () use ($receiptId): void {
            $receipt = RentalReceipt::query()->with(['customer', 'invoice.customer'])->find($receiptId);
            if ($receipt === null) {
                return;
            }

            $receiptPdf = app(RentalReceiptPdf::class);
            $reference = (string) $receipt->reference;
            $invoice = $receipt->invoice;

            $attachments = [['data' => $receiptPdf->render($receipt), 'name' => $receiptPdf->filename($receipt)]];
            $facts = [
                [__('Receipt no.'), $reference],
                [__('Amount received'), Currencies::format((float) $receipt->amount)],
            ];

            if ($invoice !== null) {
                $invoicePdf = app(RentalInvoicePdf::class);
                $attachments[] = ['data' => $invoicePdf->render($invoice), 'name' => $invoicePdf->filename($invoice)];
                $facts[] = [__('Invoice no.'), (string) $invoice->reference];
                $facts[] = [__('Balance due'), Currencies::format(max(0.0, $invoice->balance()))];
            }

            $customer = $receipt->customer ?? $invoice?->customer;

            $this->mailer->send($customer?->email, new CustomerDocument(
                subject: $this->mailer->companyName() . ' — ' . __('Receipt') . ' ' . $reference,
                heading: __('Payment received — thank you'),
                customerName: (string) ($customer->name ?? ''),
                paragraphs: [
                    __('We have received your payment of :amount. Your receipt is attached, together with your invoice.', [
                        'amount' => Currencies::format((float) $receipt->amount),
                    ]),
                ],
                facts: $facts,
                attachments: $attachments,
                label: __('Receipt') . ' ' . $reference,
            ));
        });
    }
}

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

            $this->mailer->send($quote->customer?->email, CustomerDocument::fromTemplate(
                type: 'quotation',
                customerName: (string) ($quote->customer->name ?? ''),
                vars: [
                    'reference' => $reference,
                    'total' => Currencies::format((float) $quote->total),
                    'valid_until' => $quote->valid_until?->format('j M Y') ?? '',
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

            $state = $receipts->isEmpty() ? 'unpaid' : ($invoice->balance() <= 0.0005 ? 'paid' : 'part');

            $this->mailer->send($invoice->customer?->email, CustomerDocument::fromTemplate(
                type: 'invoice',
                customerName: (string) ($invoice->customer->name ?? ''),
                vars: [
                    'reference' => $reference,
                    'total' => Currencies::format((float) $invoice->total),
                    'paid' => Currencies::format((float) $invoice->amount_paid),
                    'balance' => Currencies::format(max(0.0, $invoice->balance())),
                    'payment_state' => $state,
                ],
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

            $this->mailer->send($customer?->email, CustomerDocument::fromTemplate(
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
        });
    }
}

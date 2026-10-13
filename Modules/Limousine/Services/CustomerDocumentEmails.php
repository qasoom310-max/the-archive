<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Mail\CustomerDocument;
use App\Erp\Mail\CustomerMailer;
use App\Erp\Money\Currencies;
use Illuminate\Support\Collection;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Models\LimoReceipt;

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

            $this->mailer->send($this->addressOf($quote->customer), new CustomerDocument(
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

            $paragraphs = [__('Thank you for choosing us. Please find your invoice attached.')];
            if ($paid && $invoice->balance() <= 0.0005) {
                $paragraphs[] = __('This invoice is paid in full — your receipt is attached as well.');
            } elseif ($paid) {
                $paragraphs[] = __('We have received part of the payment — your receipt is attached. The remaining balance is shown below.');
            } else {
                $paragraphs[] = __('The amount due is shown below. Payment details are printed on the invoice.');
            }

            $this->sendInvoiceEmail($invoice, $receipts, $paragraphs, __('Your invoice :reference', ['reference' => (string) $invoice->reference]));
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

            $this->mailer->send($this->addressOf($customer), new CustomerDocument(
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
        }, $now);
    }

    /**
     * @param  Collection<int, LimoReceipt>  $receipts
     * @param  list<string>                  $paragraphs
     */
    private function sendInvoiceEmail(LimoInvoice $invoice, Collection $receipts, array $paragraphs, string $heading): void
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

        $this->mailer->send($this->addressOf($invoice->customer), new CustomerDocument(
            subject: $this->mailer->companyName() . ' — ' . __('Invoice') . ' ' . $reference,
            heading: $heading,
            customerName: (string) ($invoice->customer->name ?? ''),
            paragraphs: $paragraphs,
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

<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;

/**
 * A statement of account: everything charged and everything paid, in order.
 *
 * What a company asks for when it wants to check our figures against its own.
 * A list of unpaid invoices does not answer that — it cannot show that a bill
 * WAS paid, when, or against which receipt, which is exactly what an accounts
 * department is reconciling.
 *
 * The opening balance carries in everything before the window, so a statement
 * for June alone still starts from what was owed on the 1st. Without it a range
 * would read as though the account began that morning at zero.
 */
final class LimoStatement
{
    /**
     * @return array<string, mixed>
     */
    public function build(LimoCustomer $customer, string $from = '', string $to = ''): array
    {
        $fromDate = $from !== '' ? Carbon::parse($from)->startOfDay() : null;
        $toDate = $to !== '' ? Carbon::parse($to)->endOfDay() : null;

        $invoices = LimoInvoice::query()
            ->with(['booking:id,reference,company_reference'])
            ->where('customer_id', $customer->id)
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        // A payment shows the same company reference as the bill it answers,
        // so a row of receipts can be traced back to the customer's own order
        // number — hence `invoice.booking` as well as the receipt's own
        // booking. Nested eager loading needs the foreign key selected too.
        $receipts = LimoReceipt::query()
            ->with([
                'booking:id,reference,company_reference',
                'invoice:id,reference,booking_id',
                'invoice.booking:id,reference,company_reference',
            ])
            ->where('customer_id', $customer->id)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        // Everything before the window, netted — what the account stood at when
        // the period opened.
        $opening = round(
            $invoices->filter(fn (LimoInvoice $i): bool => $this->before($i->issue_date, $fromDate))->sum('total')
            - $receipts->filter(fn (LimoReceipt $r): bool => $this->before($r->date, $fromDate))->sum('amount'),
            3,
        );

        $lines = [];

        foreach ($invoices as $invoice) {
            if (! $this->inWindow($invoice->issue_date, $fromDate, $toDate)) {
                continue;
            }

            $lines[] = [
                'date' => $invoice->issue_date,
                'sort' => [$invoice->issue_date->timestamp ?? 0, 0, (int) $invoice->id],
                'reference' => (string) ($invoice->reference ?? ''),
                // The customer's own order number for the trip, so their
                // accounts department can match the line to their paperwork.
                'company_reference' => (string) ($invoice->booking->company_reference ?? ''),
                'description' => $this->describe($invoice),
                'charge' => round((float) $invoice->total, 3),
                'payment' => 0.0,
                'receipt' => '',
                'method' => '',
            ];
        }

        foreach ($receipts as $receipt) {
            if (! $this->inWindow($receipt->date, $fromDate, $toDate)) {
                continue;
            }

            $lines[] = [
                'date' => $receipt->date,
                // Payments sort after charges on the same day: money answers a
                // bill, so a statement that pays before it charges reads wrong.
                'sort' => [$receipt->date->timestamp ?? 0, 1, (int) $receipt->id],
                'reference' => (string) ($receipt->invoice->reference ?? ''),
                'company_reference' => (string) (
                    $receipt->invoice->booking->company_reference
                    ?? $receipt->booking->company_reference
                    ?? ''
                ),
                'description' => __('Payment received'),
                'charge' => 0.0,
                'payment' => round((float) $receipt->amount, 3),
                // The number the customer quotes back at us when they query it.
                'receipt' => (string) ($receipt->reference ?? ''),
                'method' => __(ucfirst((string) $receipt->method)),
            ];
        }

        usort($lines, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        // Running balance, so any line can be checked without adding up the
        // whole page by hand.
        $balance = $opening;
        foreach ($lines as $i => $line) {
            $balance = round($balance + $line['charge'] - $line['payment'], 3);
            $lines[$i]['balance'] = $balance;
        }

        return [
            'customer' => $customer,
            'from' => $fromDate,
            'to' => $toDate,
            'opening' => $opening,
            'lines' => $lines,
            'charged' => round(array_sum(array_column($lines, 'charge')), 3),
            'paid' => round(array_sum(array_column($lines, 'payment')), 3),
            'closing' => $balance,
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'companyPhone' => (string) Setting::get('company.phone', ''),
            'companyEmail' => (string) Setting::get('company.email', ''),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    /** What the line says the charge was for. */
    private function describe(LimoInvoice $invoice): string
    {
        if ($invoice->isCharge()) {
            return (string) $invoice->charge_label;
        }

        $trip = $invoice->booking->reference ?? '';

        return $trip !== '' ? __('Trip :reference', ['reference' => $trip]) : __('Limousine services');
    }

    private function before(?Carbon $date, ?Carbon $from): bool
    {
        return $from !== null && $date !== null && $date->lt($from);
    }

    private function inWindow(?Carbon $date, ?Carbon $from, ?Carbon $to): bool
    {
        if ($date === null) {
            return false;
        }

        return ! ($from !== null && $date->lt($from)) && ! ($to !== null && $date->gt($to));
    }

    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        return Pdf::loadView('limousine::statement-pdf', $data)->setPaper('a4')->output();
    }

    public function filename(LimoCustomer $customer): string
    {
        return 'statement-' . str_replace([' ', '/', '\\'], '-', $customer->name) . '.pdf';
    }

    private function logoScale(): int
    {
        $raw = (int) Setting::get('company.logo_scale', 100);

        return max(50, min(400, $raw > 0 ? $raw : 100));
    }

    private function logoPath(): ?string
    {
        $rel = Setting::get('company.logo');
        if (! is_string($rel) || $rel === '') {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($rel) ? $disk->path($rel) : null;
    }
}

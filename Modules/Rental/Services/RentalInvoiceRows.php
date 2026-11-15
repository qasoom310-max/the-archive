<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Export\DocumentLines;
use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Rental\Models\RentalInvoice;

/**
 * The one definition of an invoices-list row, read by both the screen and its
 * export — the same shape {@see \Modules\Limousine\Services\LimoQueueRows}
 * proved out, sized to this screen's own tab state.
 */
final class RentalInvoiceRows
{
    /**
     * @return Builder<RentalInvoice>
     */
    public function query(string $tab = 'all', string $search = ''): Builder
    {
        $query = RentalInvoice::query()->with('customer:id,name')->orderByDesc('id');
        $this->search($query, $search);

        if (in_array($tab, [RentalInvoice::STATUS_UNPAID, RentalInvoice::STATUS_PARTIAL, RentalInvoice::STATUS_PAID], true)) {
            $query->where('status', $tab);
        }

        return $query;
    }

    /**
     * Narrow to a customer name (or an invoice number) containing the text.
     *
     * @param  Builder<RentalInvoice>  $query
     */
    public function search(Builder $query, string $search): void
    {
        $term = trim($search);
        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term): void {
            $q->whereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"))
                ->orWhere('reference', 'like', "%{$term}%");
        });
    }

    /**
     * @param  list<int>  $ids  When given, only these rows (the ticked ones).
     * @param  string  $search  Customer name or invoice number, as typed on the screen.
     * @return list<array<string, string>>
     */
    public function all(string $tab, array $ids = [], string $search = ''): array
    {
        $query = $this->query($tab, $search);
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        return $query->get()->map(fn (RentalInvoice $i): array => $this->row($i))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(RentalInvoice $invoice): array
    {
        return [
            'reference' => (string) ($invoice->reference ?? ''),
            'customer' => (string) ($invoice->customer->name ?? ''),
            'issued' => $invoice->issue_date?->isoFormat('DD-MMM-YYYY') ?? '',
            'total' => ValueFormat::money($invoice->total),
            'paid' => ValueFormat::money($invoice->amount_paid),
            'balance' => ValueFormat::money($invoice->balance()),
            'status' => __(ucfirst((string) $invoice->status)),
        ];
    }

    /**
     * The columns of the printed invoice ({@see RentalInvoicePdf}), plus the
     * invoice number and customer the document prints in its header. What
     * the list's Excel / CSV / Print carry.
     *
     * @return array<string, string>
     */
    public function lineHeadings(): array
    {
        return [
            'service' => __('Service'), 'vehicle' => __('Vehicle'), 'from' => __('From'), 'to' => __('To'),
            'days' => __('Days/Trips'), 'rate' => __('Rate per Unit'), 'amount' => __('Amount'),
            'invoice' => __('Invoice'), 'customer' => __('Customer'),
        ];
    }

    /**
     * The same invoices as {@see all()}, written out as the printed invoice's
     * lines, then its totals box (Subtotal, Discount, charges, VAT, Total,
     * Paid, Balance due) added up across them.
     *
     * @param  list<int>  $ids  When given, only these rows (the ticked ones).
     * @return list<array<string, string>>
     */
    public function lines(string $tab, array $ids = [], string $search = ''): array
    {
        $query = $this->query($tab, $search)->with(['customer', 'order.vehicle']);
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        $invoices = $query->get();
        if ($invoices->isEmpty()) {
            return [];
        }

        $pdf = app(RentalInvoicePdf::class);
        $out = [];
        $sum = ['subtotal' => 0.0, 'discount' => 0.0, 'delivery' => 0.0, 'extra' => 0.0, 'fuel' => 0.0, 'vat' => 0.0, 'total' => 0.0, 'paid' => 0.0];

        foreach ($invoices as $invoice) {
            $reference = (string) ($invoice->reference ?? '');
            $customer = (string) ($invoice->customer->name ?? '');
            $lines = $pdf->linesFor($invoice);

            foreach ($lines as $line) {
                $out[] = [
                    'service' => (string) $line['service'],
                    'vehicle' => (string) $line['vehicle'],
                    'from' => (string) $line['from'],
                    'to' => (string) $line['to'],
                    'days' => trim($line['units'] . ' ' . $line['unitLabel']),
                    'rate' => ValueFormat::money($line['rate']),
                    'amount' => ValueFormat::money($line['amount']),
                    'invoice' => $reference,
                    'customer' => $customer,
                ];
            }

            // An invoice with no order behind it (most brought over from the
            // old system) still prints one line, for what it bills.
            if ($lines === []) {
                $out[] = [
                    'service' => __('Rental services'),
                    'amount' => ValueFormat::money((float) $invoice->subtotal > 0 ? $invoice->subtotal : $invoice->total),
                    'invoice' => $reference,
                    'customer' => $customer,
                ];
            }

            $order = $invoice->order;
            $sum['subtotal'] += (float) $invoice->subtotal;
            $sum['discount'] += (float) $invoice->discount;
            $sum['delivery'] += (float) ($order->delivery_charges ?? 0.0);
            $sum['extra'] += (float) ($order->extra_charge ?? 0.0);
            $sum['fuel'] += (float) ($order?->fuelChargeTotal() ?? 0.0);
            $sum['vat'] += (float) ($order->vat_amount ?? 0.0);
            $sum['total'] += (float) $invoice->total;
            $sum['paid'] += (float) $invoice->amount_paid;
        }

        return [...$out, ...DocumentLines::footer([
            __('Subtotal') => $sum['subtotal'],
            __('Discount') => $sum['discount'],
            __('Delivery') => $sum['delivery'] > 0 ? $sum['delivery'] : null,
            __('Extra charge') => $sum['extra'] > 0 ? $sum['extra'] : null,
            __('Fuel / service charge') => $sum['fuel'] > 0 ? $sum['fuel'] : null,
            __('VAT') => $sum['vat'],
            __('Total') => $sum['total'],
            __('Paid') => $sum['paid'] > 0 ? $sum['paid'] : null,
            __('Balance due') => $sum['total'] - $sum['paid'],
        ], 'rate', 'amount')];
    }

    /**
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'), 'customer' => __('Customer'), 'issued' => __('Issued'),
            'total' => __('Total'), 'paid' => __('Paid'), 'balance' => __('Balance'), 'status' => __('Status'),
        ];
    }
}

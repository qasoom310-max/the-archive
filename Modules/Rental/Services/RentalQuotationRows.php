<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Export\DocumentLines;
use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalQuotation;

/**
 * The one definition of a quotations-list row, read by both the screen and
 * its export.
 */
final class RentalQuotationRows
{
    /**
     * @return Builder<RentalQuotation>
     */
    public function query(string $tab = 'all', string $search = ''): Builder
    {
        $query = RentalQuotation::query()->with(['customer:id,name', 'vehicle:id,name,plate_no,color'])->orderByDesc('id');
        $this->applySearch($query, $search);

        if (in_array($tab, [
            RentalQuotation::STATUS_DRAFT, RentalQuotation::STATUS_SENT, RentalQuotation::STATUS_ACCEPTED,
            RentalQuotation::STATUS_DECLINED, RentalQuotation::STATUS_CONVERTED,
        ], true)) {
            $query->where('status', $tab);
        }

        return $query;
    }

    /**
     * Narrow a quotation query to a reference or customer name.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public function applySearch(Builder $query, string $search): void
    {
        $term = trim($search);
        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term): void {
            $q->where('reference', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"));
        });
    }

    /**
     * @param  list<int>  $ids  When given, only these rows (the ticked ones).
     * @return list<array<string, string>>
     */
    public function all(string $tab, array $ids = [], string $search = ''): array
    {
        $query = $this->query($tab, $search);
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        return $query->get()->map(fn (RentalQuotation $q): array => $this->row($q))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(RentalQuotation $quote): array
    {
        return [
            'reference' => (string) ($quote->reference ?? ''),
            'customer' => (string) ($quote->customer->name ?? ''),
            'car' => (string) ($quote->vehicle?->displayName() ?? '') . ($quote->carCount() > 1 ? ' (' . __(':count cars', ['count' => $quote->carCount()]) . ')' : ''),
            'valid_until' => $quote->valid_until?->isoFormat('DD-MMM-YYYY') ?? '',
            'total' => ValueFormat::money($quote->total),
            'status' => __(ucfirst((string) $quote->status)),
        ];
    }

    /**
     * The columns of the printed quotation ({@see RentalQuotationPdf}), plus
     * the quotation number and customer the document prints in its header.
     * What the list's Excel / CSV / Print carry.
     *
     * @return array<string, string>
     */
    public function lineHeadings(): array
    {
        return [
            'no' => __('No.'), 'service' => __('Service'), 'vehicle' => __('Vehicle'), 'from' => __('From'),
            'to' => __('To'), 'days' => __('Days'), 'rate' => __('Rate'), 'amount' => __('Amount'),
            'quotation' => __('Quotation'), 'customer' => __('Customer'),
        ];
    }

    /**
     * The same quotations as {@see all()}, written out as the printed
     * quotation's lines (one per car line, numbered per quotation), then
     * Subtotal / Discount / VAT / Total as at the foot of the document.
     *
     * @param  list<int>  $ids  When given, only these rows (the ticked ones).
     * @return list<array<string, string>>
     */
    public function lines(string $tab, array $ids = [], string $search = ''): array
    {
        // The whole car, not the list's slim columns: a line names its model.
        $query = $this->query($tab, $search)->with(['customer', 'vehicle']);
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        $quotes = $query->get();
        if ($quotes->isEmpty()) {
            return [];
        }

        $pdf = app(RentalQuotationPdf::class);
        $out = [];
        $subtotal = $discount = $vat = $total = 0.0;

        foreach ($quotes as $quote) {
            $reference = (string) ($quote->reference ?? '');
            $customer = (string) ($quote->customer->name ?? '');
            $lines = $pdf->linesFor($quote);

            foreach ($lines as $i => $line) {
                $out[] = [
                    'no' => (string) ($i + 1),
                    'service' => (string) $line['service'],
                    'vehicle' => (string) $line['vehicle'],
                    'from' => (string) $line['from'],
                    'to' => (string) $line['to'],
                    'days' => trim($line['units'] . ' ' . $line['unitLabel']),
                    'rate' => ValueFormat::money($line['rate']),
                    'amount' => ValueFormat::money($line['amount']),
                    'quotation' => $reference,
                    'customer' => $customer,
                ];
            }

            // A quotation with no car chosen yet still prints one line.
            if ($lines === []) {
                $out[] = [
                    'no' => '1',
                    'service' => __('Rental services'),
                    'amount' => ValueFormat::money($quote->subtotal),
                    'quotation' => $reference,
                    'customer' => $customer,
                ];
            }

            // The same sums the document prints: VAT on the discounted
            // subtotal at the rate every order defaults to.
            $quoteSubtotal = round((float) $quote->subtotal, 3);
            $quoteDiscount = round((float) $quote->discount, 3);
            $quoteVat = round(max(0.0, $quoteSubtotal - $quoteDiscount) * (RentalOrder::DEFAULT_VAT_RATE / 100), 3);
            $subtotal += $quoteSubtotal;
            $discount += $quoteDiscount;
            $vat += $quoteVat;
            $total += round($quoteSubtotal - $quoteDiscount + $quoteVat, 3);
        }

        return [...$out, ...DocumentLines::footer([
            __('Subtotal') => $subtotal,
            __('Discount') => $discount,
            __('VAT') => $vat,
            __('Total') => $total,
        ], 'rate', 'amount')];
    }

    /**
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'), 'customer' => __('Customer'), 'car' => __('Car'),
            'valid_until' => __('Valid until'), 'total' => __('Total'), 'status' => __('Status'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Export\DocumentLines;
use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Support\LiveEntry;

/**
 * The one definition of a quotations-list row, read by both the screen and
 * its export.
 */
final class LimoQuotationRows
{
    /**
     * @return Builder<LimoQuotation>
     */
    public function query(string $tab = 'all', bool $onlyLive = false, string $search = ''): Builder
    {
        $query = LimoQuotation::query()->with('customer:id,name')->orderByDesc('id');
        $this->applySearch($query, $search);

        if (in_array($tab, [
            LimoQuotation::STATUS_DRAFT, LimoQuotation::STATUS_SENT, LimoQuotation::STATUS_ACCEPTED,
            LimoQuotation::STATUS_DECLINED, LimoQuotation::STATUS_CONVERTED,
        ], true)) {
            $query->where('status', $tab);
        }

        // The historical CSV import keeps the old system's own quote number
        // verbatim in a 4-digit "QT/0555" shape (see LegacyQuotationImporter);
        // the app's own auto-reference always zero-pads to 5 digits
        // ("QT/00042", 8 chars vs the legacy 7). Anything longer than the
        // legacy shape was raised through the register, i.e. live.
        if ($onlyLive) {
            $query->whereRaw('LENGTH(reference) > 7')
                ->where('created_at', '>=', LiveEntry::since());
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
    public function all(string $tab, array $ids = [], bool $onlyLive = false, string $search = ''): array
    {
        $query = $this->query($tab, $onlyLive, $search);
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        return $query->get()->map(fn (LimoQuotation $q): array => $this->row($q))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(LimoQuotation $quote): array
    {
        return [
            'reference' => (string) ($quote->reference ?? ''),
            'customer' => (string) ($quote->customer->name ?? ''),
            'valid_until' => $quote->valid_until?->isoFormat('DD-MMM-YYYY') ?? '',
            'fare' => ValueFormat::money($quote->fare),
            'status' => __(ucfirst((string) $quote->status)),
        ];
    }

    /**
     * The columns of the printed quotation ({@see QuotationPdf}), plus the
     * quotation number and customer the document prints in its header.
     * What the list's Excel / CSV / Print carry.
     *
     * @return array<string, string>
     */
    public function lineHeadings(): array
    {
        return [
            'no' => __('No.'), 'service' => __('Service'), 'vehicle' => __('Vehicle Type'),
            'from' => __('From'), 'to' => __('To'), 'hours' => __('Hours/Day'), 'days' => __('Days/Trips'),
            'unit' => __('Unit'), 'rate' => __('Rate per Unit'), 'amount' => __('Amount'),
            'quotation' => __('Quotation'), 'customer' => __('Customer'),
        ];
    }

    /**
     * The same quotations as {@see all()}, written out as the printed
     * quotation's lines (numbered per quotation, extras on their own rows),
     * then Subtotal / Discount / VAT / Total as at the foot of the document.
     *
     * @param  list<int>  $ids  When given, only these rows (the ticked ones).
     * @return list<array<string, string>>
     */
    public function lines(string $tab, array $ids = [], string $search = ''): array
    {
        $query = $this->query($tab, false, $search)->with(['customer', 'legs']);
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        $quotes = $query->get();
        if ($quotes->isEmpty()) {
            return [];
        }

        $pdf = app(QuotationPdf::class);
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
                    'hours' => $line['hours'] !== null ? DocumentLines::quantity((float) $line['hours']) : '',
                    'days' => DocumentLines::quantity((float) $line['daysTrips']),
                    'unit' => DocumentLines::quantity((float) $line['unit']),
                    'rate' => ValueFormat::money($line['rate']),
                    'amount' => ValueFormat::money($line['amount']),
                    'quotation' => $reference,
                    'customer' => $customer,
                ];
                foreach ($line['extras'] as $extra) {
                    $out[] = [
                        'service' => '+ ' . $extra['name'],
                        'unit' => (string) $extra['qty'],
                        'rate' => ValueFormat::money($extra['price']),
                        'amount' => ValueFormat::money($extra['amount']),
                        'quotation' => $reference,
                        'customer' => $customer,
                    ];
                }
            }

            // A quotation with no trips on it still prints one line.
            if ($lines === []) {
                $out[] = [
                    'no' => '1',
                    'service' => __('Limousine services'),
                    'amount' => ValueFormat::money($quote->fare),
                    'quotation' => $reference,
                    'customer' => $customer,
                ];
            }

            $subtotal += (float) $quote->legs->sum(
                static fn (LimoLeg $leg): float => LimoLeg::grossFor($leg->rate_basis, (float) $leg->rate, $leg->hours, $leg->days, $leg->units) + (float) $leg->extras_total,
            );
            $discount += (float) $quote->legs->sum('discount');
            $vat += (float) $quote->legs->sum('vat');
            $total += (float) $quote->fare;
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
            'reference' => __('Reference'), 'customer' => __('Customer'),
            'valid_until' => __('Valid until'), 'fare' => __('Fare'), 'status' => __('Status'),
        ];
    }
}

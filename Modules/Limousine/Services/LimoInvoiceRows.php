<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Export\DocumentLines;
use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Support\LiveEntry;
use Modules\Limousine\Support\TripInvoiceLookup;

/**
 * The one definition of an invoices-list row, read by both the screen and its
 * export — sized to this screen's own tab + date-window + search state, the
 * same shape {@see LimoQueueRows} proved out for the booking queue.
 */
final class LimoInvoiceRows
{
    /**
     * @return Builder<LimoInvoice>
     */
    public function query(string $tab = 'all', string $from = '', string $to = '', string $search = '', bool $onlyLive = false): Builder
    {
        $query = LimoInvoice::query()->with('customer:id,name')->orderByDesc('id');

        if (in_array($tab, [LimoInvoice::STATUS_UNPAID, LimoInvoice::STATUS_PARTIAL, LimoInvoice::STATUS_PAID], true)) {
            $query->where('status', $tab);
        }

        // By when the trips ran, not when the bill was printed: an invoice
        // issued on 1 September for August's trips is August's. A bill with
        // no trip (a late fee, an undispatched quote) goes by its issue date.
        // DATE() because a date column holds "Y-m-d 00:00:00" on SQLite.
        $day = 'DATE(COALESCE(service_date, issue_date))';
        if ($from !== '') {
            $query->whereRaw($day . ' >= ?', [$from]);
        }
        if ($to !== '') {
            $query->whereRaw($day . ' <= ?', [$to]);
        }

        $term = trim($search);
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            // A whole trip number also finds an old-system invoice that names
            // the trip's booking in its notes.
            $tripInvoices = TripInvoiceLookup::isTripNumber($term) ? TripInvoiceLookup::invoiceIds($term) : [];
            $query->where(function ($q) use ($like, $tripInvoices): void {
                if ($tripInvoices !== []) {
                    $q->orWhereIn('id', $tripInvoices);
                }
                $q->orWhere('reference', 'like', $like)
                    ->orWhere('charge_label', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))
                    ->orWhereHas('booking', fn ($b) => $b->where('reference', 'like', $like))
                    // The trip number: what the invoice PDF prints as "Ref. #"
                    // and what the office quotes, so it must find the bill too.
                    // The number a trip had before the renumbering counts as well.
                    ->orWhereHas('booking.legs', fn ($l) => $l->where('reference', 'like', $like)
                        ->orWhere('previous_reference', 'like', $like));
            });
        }

        // LegacyInvoiceImporter never sets `reference` (it auto-generates the
        // same "INV/01234" shape as a live invoice, so that column can't tell
        // them apart) — but it DOES either link a single-booking invoice to a
        // booking the historical migration also imported (`imported_at` set),
        // or, for a combined/unmatched invoice, stamp `notes` with its own
        // fixed "Invoice #… | Bookings: …" shape. An invoice matching neither
        // is live. Known gap: a legacy row whose old export left BOTH the
        // booking reference and every fallback blank produces neither signal
        // and would read as live here too — rare in practice, but this is a
        // heuristic, not a guarantee; spot-check before relying on it alone.
        if ($onlyLive) {
            $query->where(function (Builder $q): void {
                $q->whereDoesntHave('booking', fn ($b) => $b->whereNotNull('imported_at'))
                    ->where(function (Builder $q2): void {
                        $q2->whereNull('notes')->orWhere('notes', 'not like', 'Invoice #%');
                    });
            })->where('created_at', '>=', LiveEntry::since());
        }

        return $query;
    }

    /**
     * @param  list<int>  $ids  When given, only these rows (the ticked ones).
     * @return list<array<string, string>>
     */
    public function all(string $tab, string $from, string $to, string $search, array $ids = [], bool $onlyLive = false): array
    {
        $query = $this->query($tab, $from, $to, $search, $onlyLive);
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        return $query->get()->map(fn (LimoInvoice $i): array => $this->row($i))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(LimoInvoice $invoice): array
    {
        return [
            'reference' => (string) ($invoice->reference ?? ''),
            'customer' => (string) ($invoice->customer->name ?? ''),
            'issued' => $invoice->issue_date?->isoFormat('DD-MMM-YYYY') ?? '',
            'service' => $invoice->service_date?->isoFormat('DD-MMM-YYYY') ?? '',
            'total' => ValueFormat::money($invoice->total),
            'paid' => ValueFormat::money($invoice->amount_paid),
            'balance' => ValueFormat::money($invoice->balance()),
            'status' => __(ucfirst((string) $invoice->status)),
        ];
    }

    /**
     * The columns of the printed invoice ({@see LimoCombinedInvoicePdf}), plus
     * the invoice number and customer the document prints in its header.
     * What the list's Excel / CSV / Print carry.
     *
     * @return array<string, string>
     */
    public function lineHeadings(): array
    {
        return [
            'serial' => __('Sl'), 'trip' => __('Ref. #'), 'service' => __('Service'), 'vehicle' => __('Car Type'),
            'from' => __('From'), 'to' => __('To'), 'date' => __('Service date'), 'company_reference' => __('Company ref.'),
            'pax' => __('Pax name'), 'total' => __('Total'), 'invoice' => __('Invoice'), 'customer' => __('Customer'),
        ];
    }

    /**
     * The same invoices as {@see all()}, written out as the printed invoice's
     * lines: one row per trip (its extras on their own rows under it), in the
     * order the trips ran, then Total / Paid / Balance due as at the foot of
     * the document.
     *
     * @param  list<int>  $ids  When given, only these rows (the ticked ones).
     * @return list<array<string, string>>
     */
    public function lines(string $tab, string $from, string $to, string $search, array $ids = []): array
    {
        $query = $this->query($tab, $from, $to, $search)
            // The whole customer, not just its name: a company's trips print
            // the passenger, a private customer's their own name.
            ->with(['customer', 'booking.legs'])
            ->reorder()
            ->orderByRaw('COALESCE(service_date, issue_date)')
            ->orderBy('id');
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        $invoices = $query->get();
        if ($invoices->isEmpty()) {
            return [];
        }

        $out = [];
        foreach (app(LimoCombinedInvoicePdf::class)->rows($invoices) as $row) {
            $invoice = (string) ($row['invoice'] ?? '');
            $customer = (string) ($row['customer'] ?? '');
            $out[] = [
                'serial' => (string) $row['serial'],
                'trip' => (string) $row['trip'],
                'service' => (string) $row['service'],
                'vehicle' => (string) $row['vehicle'],
                'from' => (string) $row['from'],
                'to' => (string) $row['to'],
                'date' => (string) $row['date'],
                'company_reference' => (string) $row['company_reference'],
                'pax' => (string) $row['pax'],
                'total' => ValueFormat::money($row['total']),
                'invoice' => $invoice,
                'customer' => $customer,
            ];
            foreach ($row['extras'] ?? [] as $extra) {
                $out[] = [
                    'service' => DocumentLines::extraLabel($extra),
                    'total' => ValueFormat::money($extra['amount']),
                    'invoice' => $invoice,
                    'customer' => $customer,
                ];
            }
        }

        $total = round((float) $invoices->sum('total'), 3);
        $paid = round((float) $invoices->sum('amount_paid'), 3);

        return [...$out, ...DocumentLines::footer([
            __('Total') => $total,
            __('Paid') => $paid > 0 ? $paid : null,
            __('Balance due') => round($total - $paid, 3),
        ], 'pax', 'total')];
    }

    /**
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'), 'customer' => __('Customer'), 'issued' => __('Issued'), 'service' => __('Service date'),
            'total' => __('Total'), 'paid' => __('Paid'), 'balance' => __('Balance'), 'status' => __('Status'),
        ];
    }
}

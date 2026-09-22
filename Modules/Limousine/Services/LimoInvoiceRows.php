<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Support\LiveEntry;

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

        if ($from !== '') {
            $query->whereDate('issue_date', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('issue_date', '<=', $to);
        }

        $term = trim($search);
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('reference', 'like', $like)
                    ->orWhere('charge_label', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))
                    ->orWhereHas('booking', fn ($b) => $b->where('reference', 'like', $like));
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
            'total' => ValueFormat::money($invoice->total),
            'paid' => ValueFormat::money($invoice->amount_paid),
            'balance' => ValueFormat::money($invoice->balance()),
            'status' => __(ucfirst((string) $invoice->status)),
        ];
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

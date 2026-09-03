<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Limousine\Models\LimoInvoice;

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
    public function query(string $tab = 'all', string $from = '', string $to = '', string $search = ''): Builder
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

        return $query;
    }

    /**
     * @return list<array<string, string>>
     */
    public function all(string $tab, string $from, string $to, string $search): array
    {
        return $this->query($tab, $from, $to, $search)->get()->map(fn (LimoInvoice $i): array => $this->row($i))->all();
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

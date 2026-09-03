<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

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
    public function query(string $tab = 'all'): Builder
    {
        $query = RentalInvoice::query()->with('customer:id,name')->orderByDesc('id');

        if (in_array($tab, [RentalInvoice::STATUS_UNPAID, RentalInvoice::STATUS_PARTIAL, RentalInvoice::STATUS_PAID], true)) {
            $query->where('status', $tab);
        }

        return $query;
    }

    /**
     * @return list<array<string, string>>
     */
    public function all(string $tab): array
    {
        return $this->query($tab)->get()->map(fn (RentalInvoice $i): array => $this->row($i))->all();
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

<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Rental\Models\RentalReceipt;

/**
 * The one definition of a receipts-list row, read by both the screen and its
 * export.
 */
final class RentalReceiptRows
{
    /**
     * @return Builder<RentalReceipt>
     */
    public function query(string $search = ''): Builder
    {
        $query = RentalReceipt::query()->with(['customer:id,name', 'invoice:id,reference'])->orderByDesc('id');

        $term = trim($search);
        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                $q->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        return $query;
    }

    /**
     * @return list<array<string, string>>
     */
    public function all(string $search): array
    {
        return $this->query($search)->get()->map(fn (RentalReceipt $r): array => $this->row($r))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(RentalReceipt $receipt): array
    {
        return [
            'reference' => (string) ($receipt->reference ?? ''),
            'customer' => (string) ($receipt->customer->name ?? ''),
            'invoice' => (string) ($receipt->invoice->reference ?? ''),
            'date' => $receipt->date?->isoFormat('DD-MMM-YYYY') ?? '',
            'method' => __(ucfirst((string) $receipt->method)),
            'amount' => ValueFormat::money($receipt->amount),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'), 'customer' => __('Customer'), 'invoice' => __('Invoice'),
            'date' => __('Date'), 'method' => __('Method'), 'amount' => __('Amount'),
        ];
    }
}

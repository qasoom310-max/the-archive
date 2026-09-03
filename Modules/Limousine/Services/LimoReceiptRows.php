<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Limousine\Models\LimoReceipt;

/**
 * The one definition of a receipts-list row, read by both the screen and its
 * export — sized to this screen's own tab + method + search state. The
 * accountant's grouped "to confirm" view is a presentation of these same
 * rows, so the export reads whichever ones the tab, method and search select.
 */
final class LimoReceiptRows
{
    /**
     * @return Builder<LimoReceipt>
     */
    public function query(string $tab = '', string $method = '', string $search = ''): Builder
    {
        $query = LimoReceipt::query()->with(['customer:id,name', 'invoice:id,reference'])->orderByDesc('id');

        if ($tab === 'to_confirm') {
            $query->whereNull('confirmed_at');
        } elseif ($tab === 'confirmed') {
            $query->whereNotNull('confirmed_at');
        }

        if (in_array($method, ['cash', 'card', 'benefit', 'transfer'], true)) {
            $query->where('method', $method);
        }

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
    public function all(string $tab, string $method, string $search): array
    {
        return $this->query($tab, $method, $search)->get()->map(fn (LimoReceipt $r): array => $this->row($r))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(LimoReceipt $receipt): array
    {
        return [
            'reference' => (string) ($receipt->reference ?? ''),
            'customer' => (string) ($receipt->customer->name ?? ''),
            'invoice' => (string) ($receipt->invoice->reference ?? ''),
            'date' => $receipt->date?->isoFormat('DD-MMM-YYYY') ?? '',
            'method' => __(ucfirst((string) $receipt->method)),
            'amount' => ValueFormat::money($receipt->amount),
            'confirmed' => $receipt->isConfirmed() ? __('Confirmed') : __('Unconfirmed'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'), 'customer' => __('Customer'), 'invoice' => __('Invoice'),
            'date' => __('Date'), 'method' => __('Method'), 'amount' => __('Amount'), 'confirmed' => __('Confirmed'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Limousine\Models\LimoQuotation;

/**
 * The one definition of a quotations-list row, read by both the screen and
 * its export.
 */
final class LimoQuotationRows
{
    /**
     * @return Builder<LimoQuotation>
     */
    public function query(string $tab = 'all'): Builder
    {
        $query = LimoQuotation::query()->with('customer:id,name')->orderByDesc('id');

        if (in_array($tab, [
            LimoQuotation::STATUS_DRAFT, LimoQuotation::STATUS_SENT, LimoQuotation::STATUS_ACCEPTED,
            LimoQuotation::STATUS_DECLINED, LimoQuotation::STATUS_CONVERTED,
        ], true)) {
            $query->where('status', $tab);
        }

        return $query;
    }

    /**
     * @param  list<int>  $ids  When given, only these rows (the ticked ones).
     * @return list<array<string, string>>
     */
    public function all(string $tab, array $ids = []): array
    {
        $query = $this->query($tab);
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

<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
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

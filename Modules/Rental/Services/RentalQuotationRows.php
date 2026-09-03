<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
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
    public function query(string $tab = 'all'): Builder
    {
        $query = RentalQuotation::query()->with(['customer:id,name', 'vehicle:id,name,plate_no,color'])->orderByDesc('id');

        if (in_array($tab, [
            RentalQuotation::STATUS_DRAFT, RentalQuotation::STATUS_SENT, RentalQuotation::STATUS_ACCEPTED,
            RentalQuotation::STATUS_DECLINED, RentalQuotation::STATUS_CONVERTED,
        ], true)) {
            $query->where('status', $tab);
        }

        return $query;
    }

    /**
     * @return list<array<string, string>>
     */
    public function all(string $tab): array
    {
        return $this->query($tab)->get()->map(fn (RentalQuotation $q): array => $this->row($q))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(RentalQuotation $quote): array
    {
        return [
            'reference' => (string) ($quote->reference ?? ''),
            'customer' => (string) ($quote->customer->name ?? ''),
            'car' => (string) ($quote->vehicle?->displayName() ?? ''),
            'valid_until' => $quote->valid_until?->isoFormat('DD-MMM-YYYY') ?? '',
            'total' => ValueFormat::money($quote->total),
            'status' => __(ucfirst((string) $quote->status)),
        ];
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

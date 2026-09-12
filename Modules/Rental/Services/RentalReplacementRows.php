<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Rental\Models\RentalReplacement;

/**
 * The one definition of a replacements-list row, read by both the screen and
 * its export.
 */
final class RentalReplacementRows
{
    /**
     * @return Builder<RentalReplacement>
     */
    public function query(string $tab = 'all'): Builder
    {
        $query = RentalReplacement::query()
            ->with(['customer:id,name', 'originalVehicle:id,name,plate_no,color', 'replacementVehicle:id,name,plate_no,color'])
            ->orderByDesc('id');

        if (in_array($tab, [RentalReplacement::STATUS_ACTIVE, RentalReplacement::STATUS_CLOSED], true)) {
            $query->where('status', $tab);
        }

        return $query;
    }

    /**
     * @param  list<int>  $ids  the rows ticked on screen; empty = the whole list
     * @return list<array<string, string>>
     */
    public function all(string $tab, array $ids = []): array
    {
        $query = $this->query($tab);
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        return $query->get()->map(fn (RentalReplacement $r): array => $this->row($r))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(RentalReplacement $replacement): array
    {
        return [
            'reference' => (string) ($replacement->reference ?? ''),
            'customer' => (string) ($replacement->customer->name ?? ''),
            'original_car' => (string) ($replacement->originalVehicle?->displayName() ?? ''),
            'replacement_car' => (string) ($replacement->replacementVehicle?->displayName() ?? ''),
            'date' => $replacement->date?->isoFormat('DD-MMM-YYYY') ?? '',
            'status' => __(ucfirst((string) $replacement->status)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'), 'customer' => __('Customer'), 'original_car' => __('Original car'),
            'replacement_car' => __('Replacement car'), 'date' => __('Date'), 'status' => __('Status'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Rental\Models\RentalMaintenance;

/**
 * The one definition of a maintenance-list row, read by both the screen and
 * its export.
 */
final class RentalMaintenanceRows
{
    /**
     * @return Builder<RentalMaintenance>
     */
    public function query(string $tab = 'all'): Builder
    {
        $query = RentalMaintenance::query()->with('vehicle:id,name,plate_no,color')->orderByDesc('id');

        if (in_array($tab, [
            RentalMaintenance::STATUS_PENDING, RentalMaintenance::STATUS_APPROVED,
            RentalMaintenance::STATUS_IN_PROGRESS, RentalMaintenance::STATUS_DONE,
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
        return $this->query($tab)->get()->map(fn (RentalMaintenance $m): array => $this->row($m))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(RentalMaintenance $record): array
    {
        return [
            'reference' => (string) ($record->reference ?? ''),
            'car' => (string) ($record->vehicle?->displayName() ?? ''),
            'type' => __(ucfirst(str_replace('_', ' ', (string) $record->type))),
            'priority' => __(ucfirst((string) $record->priority)),
            'date' => $record->date?->isoFormat('DD-MMM-YYYY') ?? '',
            'cost' => ValueFormat::money($record->cost),
            'status' => __($record->statusLabel()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'), 'car' => __('Car'), 'type' => __('Type'),
            'priority' => __('Priority'), 'date' => __('Date'), 'cost' => __('Cost'), 'status' => __('Status'),
        ];
    }
}

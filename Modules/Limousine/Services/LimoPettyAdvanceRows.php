<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Limousine\Models\LimoPettyAdvance;

/**
 * The one definition of a petty-cash-list row, read by both the screen and
 * its export — the open/cleared advances, not the Report tab's separate
 * by-category / by-driver aggregation.
 */
final class LimoPettyAdvanceRows
{
    /**
     * @return Builder<LimoPettyAdvance>
     */
    public function query(string $tab = 'open'): Builder
    {
        $query = LimoPettyAdvance::query()->with('driver:id,name')->orderByDesc('id');

        if ($tab === 'open') {
            $query->where('status', '!=', LimoPettyAdvance::STATUS_CLEARED);
        } elseif ($tab === 'cleared') {
            $query->where('status', LimoPettyAdvance::STATUS_CLEARED);
        }

        return $query;
    }

    /**
     * @return list<array<string, string>>
     */
    public function all(string $tab): array
    {
        return $this->query($tab)->get()->map(fn (LimoPettyAdvance $a): array => $this->row($a))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(LimoPettyAdvance $advance): array
    {
        $receipts = $advance->isCleared() ? $advance->receipts_total : $advance->linesTotal();

        return [
            'reference' => (string) ($advance->reference ?? ''),
            'driver' => (string) ($advance->driver->name ?? ''),
            'date' => $advance->date?->isoFormat('DD-MMM-YYYY') ?? '',
            'given' => ValueFormat::money($advance->amount),
            'receipts' => ValueFormat::money($receipts),
            'status' => __(ucfirst((string) $advance->status)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'), 'driver' => __('Driver'), 'date' => __('Date'),
            'given' => __('Given'), 'receipts' => __('Receipts'), 'status' => __('Status'),
        ];
    }
}

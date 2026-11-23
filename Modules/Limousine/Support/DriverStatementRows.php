<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Services\PettyCash;

/**
 * A driver's cash statement as rows, for the screen's downloads (Excel, PDF,
 * print, CSV): the same figures the Driver statement tab shows.
 */
final class DriverStatementRows
{
    public function __construct(private readonly PettyCash $petty) {}

    /**
     * Y-m → that month; anything else → all time.
     *
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    public static function window(string $month): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return [null, null];
        }

        $from = Carbon::parse($month . '-01')->startOfMonth();

        return [$from, $from->copy()->endOfMonth()];
    }

    private const KEYS = ['date', 'movement', 'petty', 'details', 'expense', 'vat', 'original', 'in', 'out', 'balance'];

    /** @return array<string, string> field => label */
    public function headings(): array
    {
        return array_combine(self::KEYS, [__('Date'), __('Movement'), __('Petty cash'), __('Details'), __('Expense'), __('VAT'), __('Original received'), __('In'), __('Out'), __('Balance')]);
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    private function keyed(array $cells): array
    {
        return array_combine(self::KEYS, $cells);
    }

    /** @return list<array<string, string>> */
    public function rows(int $driverId, string $month): array
    {
        [$from, $to] = self::window($month);
        $s = $this->petty->driverStatement($driverId, $from, $to);
        $dp = \App\Erp\Money\Currencies::active()->decimals;
        $num = static fn (float $v): string => number_format($v, $dp, '.', '');
        $money = static fn (float $v): string => $v > 0.0 ? $num($v) : '';
        $kinds = ['given' => __('Cash given'), 'bill' => __('Bill'), 'paid_back' => __('Paid back to him'), 'short' => __('Short')];

        $out = [$this->keyed([
            '', __('Opening balance'), '', '', '', '', '', '', '', $num($s['opening']),
        ])];
        foreach ($s['rows'] as $r) {
            $out[] = $this->keyed([$r['date'], $kinds[$r['kind']] ?? $r['kind'], $r['reference'], $r['detail'], $r['expense'], $money($r['vat']), $r['original'], $money($r['in']), $money($r['out']), $num($r['balance'])]);
        }
        $out[] = $this->keyed(['', __('Closing balance'), '', '', '', '', '', $num($s['in']), $num($s['out']), $num($s['closing'])]);

        return $out;
    }

    public function title(int $driverId, string $month): string
    {
        [$from] = self::window($month);
        $name = (string) (LimoDriver::query()->whereKey($driverId)->value('name') ?? '');

        return __('Driver cash statement') . ' — ' . $name . ' — ' . ($from !== null ? $from->translatedFormat('F Y') : __('All time'));
    }
}

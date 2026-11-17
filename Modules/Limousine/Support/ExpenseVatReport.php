<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoExpense;

/**
 * The VAT paid on limousine expenses in one quarter, set out for the VAT
 * return: everything in BHD, and the VAT split by where it was paid.
 *
 *  - Bahrain VAT (the 10% rate): what the return can claim back.
 *  - Foreign VAT (the 15% rate, Saudi Arabia): paid to another country's tax
 *    authority, so it is a cost and is listed apart, never added to the claim.
 *  - Bills with no VAT: counted and totalled only.
 *
 * The rate decides which is which, because that is how the expense form
 * already names them ("VAT 10%" Bahrain, "VAT 15%" Saudi Arabia). A bill in
 * another currency carrying 10% is a Bahrain supplier invoicing in that
 * currency, and counts as Bahrain VAT.
 */
final class ExpenseVatReport
{
    public const BAHRAIN_RATE = 10;

    public readonly Carbon $from;

    public readonly Carbon $to;

    public function __construct(public readonly int $year, public readonly int $quarter)
    {
        $this->from = Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();
        $this->to = $this->from->copy()->addMonths(2)->endOfMonth();
    }

    /** The quarter today falls in. */
    public static function current(): self
    {
        $now = Carbon::now();

        return new self((int) $now->year, (int) ceil($now->month / 3));
    }

    /**
     * @return array{
     *     bahrain: list<LimoExpense>, foreign: list<LimoExpense>,
     *     totals: array{bahrain_net: float, bahrain_vat: float, bahrain_total: float, foreign_net: float, foreign_vat: float, foreign_total: float, none_count: int, none_total: float, all_total: float},
     *     missing: int
     * } `missing` counts Bahrain VAT bills with no supplier name or no photo of the bill
     */
    public function report(): array
    {
        $expenses = LimoExpense::query()
            ->whereDate('date', '>=', $this->from->toDateString())
            ->whereDate('date', '<=', $this->to->toDateString())
            ->orderBy('date')->orderBy('id')
            ->get();

        $bahrain = [];
        $foreign = [];
        $totals = ['bahrain_net' => 0.0, 'bahrain_vat' => 0.0, 'bahrain_total' => 0.0, 'foreign_net' => 0.0, 'foreign_vat' => 0.0, 'foreign_total' => 0.0, 'none_count' => 0, 'none_total' => 0.0, 'all_total' => 0.0];
        $missing = 0;

        foreach ($expenses as $expense) {
            $totals['all_total'] += $expense->amount;

            if ($expense->vat_amount <= 0.0) {
                $totals['none_count']++;
                $totals['none_total'] += $expense->amount;

                continue;
            }

            $side = $expense->vat_rate === self::BAHRAIN_RATE ? 'bahrain' : 'foreign';
            $totals[$side . '_net'] += $expense->net_amount;
            $totals[$side . '_vat'] += $expense->vat_amount;
            $totals[$side . '_total'] += $expense->amount;

            if ($side === 'bahrain') {
                $bahrain[] = $expense;
                if (trim((string) $expense->payee) === '' || (string) $expense->bill_path === '') {
                    $missing++;
                }
            } else {
                $foreign[] = $expense;
            }
        }

        foreach (['bahrain_net', 'bahrain_vat', 'bahrain_total', 'foreign_net', 'foreign_vat', 'foreign_total', 'none_total', 'all_total'] as $key) {
            $totals[$key] = round($totals[$key], 3);
        }

        return ['bahrain' => $bahrain, 'foreign' => $foreign, 'totals' => $totals, 'missing' => $missing];
    }
}

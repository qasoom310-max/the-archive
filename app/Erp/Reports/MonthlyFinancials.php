<?php

declare(strict_types=1);

namespace App\Erp\Reports;

use App\Models\ExpensePayment;
use App\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderLine;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;

/**
 * The accountant-correct monthly P&L:
 *
 *   Gross Profit = Sales − COGS
 *   Net Profit   = Gross Profit − Operating Expenses
 *
 * where COGS (cost of goods *sold*) is the cost of what each sold product
 * actually consumed: its recipe components (ingredient/product cost; condiments
 * untracked = 0), or — for a resale product with no recipe — its own cost
 * price. Costs are the items' CURRENT cost price (the system doesn't snapshot
 * cost per sale), which is a fair approximation for a café.
 *
 * Sales here is the order total (the amount the customer paid, tax included),
 * matching the Daily Summary. Decoupled + defensive: guards on table existence
 * so it's a zero report when POS / expenses aren't present.
 */
final class MonthlyFinancials
{
    /**
     * @return array{sales: float, cogs: float, gross: float, expenses: float, payroll: float, net: float}
     */
    public function forMonth(string $period): array
    {
        [$start, $end] = $this->monthWindow($period);

        $sales = 0.0;
        $cogs = 0.0;

        if (Schema::hasTable('pos_orders') && Schema::hasTable('pos_order_lines')) {
            $orderIds = PosOrder::query()
                ->where('state', 'done')
                ->whereBetween('ordered_at', [$start, $end])
                ->pluck('id');

            $sales = round((float) PosOrder::query()->whereIn('id', $orderIds)->sum('total'), 3);
            $cogs = $this->costOfGoodsSold($orderIds->all());
        }

        $expenses = 0.0;
        if (Schema::hasTable('expense_payments')) {
            $expenses = round((float) ExpensePayment::query()->where('period', $period)->sum('amount'), 3);
        }

        // Payroll = paid salary slips for the month (an operating cost).
        $payroll = 0.0;
        if (Schema::hasTable('payslips')) {
            $payroll = round((float) Payslip::query()->where('period', $period)->sum('net'), 3);
        }

        $gross = round($sales - $cogs, 3);

        return [
            'sales' => $sales,
            'cogs' => $cogs,
            'gross' => $gross,
            'expenses' => $expenses,
            'payroll' => $payroll,
            'net' => round($gross - $expenses - $payroll, 3),
        ];
    }

    /**
     * Cost of goods sold for a set of (done) orders: per sold product, the
     * recipe-component cost × qty, or the resale product's own cost × qty.
     *
     * @param  list<int>  $orderIds
     */
    private function costOfGoodsSold(array $orderIds): float
    {
        if ($orderIds === []) {
            return 0.0;
        }

        // Total quantity sold per product across the month.
        $qtyByProduct = [];
        PosOrderLine::query()
            ->whereIn('pos_order_id', $orderIds)
            ->whereNotNull('pos_product_id')
            ->get(['pos_product_id', 'qty'])
            ->each(function (PosOrderLine $line) use (&$qtyByProduct): void {
                $pid = (int) $line->pos_product_id;
                $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0.0) + (float) $line->qty;
            });

        if ($qtyByProduct === []) {
            return 0.0;
        }

        $productIds = array_keys($qtyByProduct);
        $ownCost = PosProduct::query()->whereIn('id', $productIds)->pluck('cost_price', 'id');

        $recipes = PosProductRecipe::query()->whereIn('parent_product_id', $productIds)->get();
        $recipesByParent = $recipes->groupBy('parent_product_id');

        // Component unit costs, resolved in bulk.
        $compProductCost = PosProduct::query()
            ->whereIn('id', $recipes->pluck('component_product_id')->filter()->unique()->all())
            ->pluck('cost_price', 'id');
        $compIngredientCost = PosIngredient::query()
            ->whereIn('id', $recipes->pluck('component_ingredient_id')->filter()->unique()->all())
            ->pluck('cost_price', 'id');

        $cogs = 0.0;

        foreach ($qtyByProduct as $productId => $qtySold) {
            $recipeLines = $recipesByParent->get($productId);

            if ($recipeLines !== null && $recipeLines->isNotEmpty()) {
                // Made product: cost = sum of component costs per unit.
                $unitCost = 0.0;
                foreach ($recipeLines as $row) {
                    $componentCost = 0.0;
                    if ($row->component_product_id !== null) {
                        $componentCost = (float) ($compProductCost[$row->component_product_id] ?? 0);
                    } elseif ($row->component_ingredient_id !== null) {
                        $componentCost = (float) ($compIngredientCost[$row->component_ingredient_id] ?? 0);
                    }
                    // Condiment components have no tracked cost (counts as 0).
                    $unitCost += (float) $row->quantity_consumed * $componentCost;
                }
                $cogs += $unitCost * $qtySold;
            } else {
                // Resale product: its own cost price.
                $cogs += (float) ($ownCost[$productId] ?? 0) * $qtySold;
            }
        }

        return round($cogs, 3);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthWindow(string $period): array
    {
        $start = Carbon::parse($period . '-01')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }
}

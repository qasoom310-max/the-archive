<?php

declare(strict_types=1);

namespace App\Erp\Reports;

use App\Models\ExpensePayment;
use App\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
 *   Net Profit   = Gross Profit − Operating Expenses − Payroll − Delivery absorbed
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
     * @return array{sales: float, cogs: float, gross: float, expenses: float, payroll: float, delivery: float, delivery_recovered: float, uncollected: float, in_transit: float, not_in_account: float, net: float}
     */
    public function forMonth(string $period): array
    {
        [$start, $end] = $this->monthWindow($period);

        $sales = 0.0;
        $cogs = 0.0;
        // Delivery, the two halves. `delivery` is what WE absorbed (the driver
        // we paid out of pocket) — a real operating cost that lived only in the
        // accounting ledger until now, so this report understated it. It is
        // subtracted below.
        //
        // `delivery_recovered` is what customers paid for delivery. It is
        // display-only context: it already sits INSIDE `sales` (delivery_charge
        // is part of the order total), so adding it again would double-count.
        $delivery = 0.0;
        $deliveryRecovered = 0.0;

        // Sales are counted when the sale HAPPENS, not when the cash lands (the
        // accountant-correct basis for profit). That's why a month can show
        // healthy sales while the money is still out there — so we also report
        // how much of it hasn't reached the account yet.
        $uncollected = 0.0;
        $inTransit = 0.0;

        if (Schema::hasTable('pos_orders') && Schema::hasTable('pos_order_lines')) {
            $orderIds = PosOrder::query()
                ->where('state', 'done')
                ->whereBetween('ordered_at', [$start, $end])
                ->pluck('id');

            $sales = round((float) PosOrder::query()->whereIn('id', $orderIds)->sum('total'), 3);
            $cogs = $this->costOfGoodsSold($orderIds->all());

            $delivery = round((float) PosOrder::query()->whereIn('id', $orderIds)->sum('delivery_fee'), 3);

            // Column added later than the others — guard so a not-yet-migrated
            // database reports zero instead of erroring.
            if (Schema::hasColumn('pos_orders', 'delivery_charge')) {
                $deliveryRecovered = round((float) PosOrder::query()->whereIn('id', $orderIds)->sum('delivery_charge'), 3);
            }

            // Still owed by customers — a pay-on-delivery order is Done (the
            // sale happened) long before anyone hands over money.
            $uncollected = round((float) PosOrder::query()
                ->whereIn('id', $orderIds)
                ->whereRaw('paid_total < total - 0.001')
                ->sum(DB::raw('total - paid_total')), 3);

            // Collected, but the delivery company is still holding it. Net of
            // the fee they keep, so this is what should actually reach the bank.
            if (Schema::hasColumn('pos_orders', 'pos_settlement_id')) {
                $held = PosOrder::query()
                    ->whereIn('id', $orderIds)
                    ->where('channel', 'remote')
                    ->whereRaw('paid_total >= total - 0.001')
                    ->where(function ($q): void {
                        $q->whereNull('pos_settlement_id')
                            ->orWhereHas('settlement', function ($s): void {
                                $s->where('state', '!=', 'received');
                            });
                    });

                $inTransit = round((float) $held->sum('total') - (float) $held->sum('delivery_fee'), 3);
            }
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
            'delivery' => $delivery,
            'delivery_recovered' => $deliveryRecovered,
            // Cash reality, kept OUT of the profit maths: profit is earned when
            // the sale is made; these just say where the money currently is.
            'uncollected' => $uncollected,
            'in_transit' => $inTransit,
            'not_in_account' => round($uncollected + $inTransit, 3),
            'net' => round($gross - $expenses - $payroll - $delivery, 3),
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

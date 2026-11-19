<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money in and out over a period, as the AI office's Accounts Manager follows it.
 * Read straight from each section's own records, every row tagged with where it came
 * from. Payroll is given as totals per month only — never one person's salary.
 *
 * `from` and `to` (YYYY-MM-DD, at most 93 days apart) are required, so one answer stays
 * a readable size.
 */
final class OfficeMoneyController
{
    private const MAX_DAYS = 93;

    public function in(Request $request): JsonResponse
    {
        $period = $this->period($request);
        if ($period instanceof JsonResponse) {
            return $period;
        }
        [$from, $to] = $period;

        $rows = [];

        if (Schema::hasTable('limo_receipts')) {
            $query = DB::table('limo_receipts as r')
                ->leftJoin('rental_customers as c', 'c.id', '=', 'r.customer_id')
                ->leftJoin('limo_invoices as i', 'i.id', '=', 'r.invoice_id')
                ->whereDate('r.date', '>=', $from)->whereDate('r.date', '<=', $to)
                ->orderBy('r.date')->orderBy('r.id')
                ->get(['r.reference', 'r.date', 'r.amount', 'r.method', 'r.confirmed_at', 'r.confirmed_by', 'r.auto', 'c.name as customer', 'i.reference as invoice']);
            foreach ($query as $r) {
                $rows[] = [
                    'source' => 'limousine receipt', 'reference' => $r->reference, 'date' => substr((string) $r->date, 0, 10),
                    'amount' => round((float) $r->amount, 3), 'method' => $r->method, 'customer' => $r->customer, 'invoice' => $r->invoice,
                    'confirmed' => $r->confirmed_at !== null, 'confirmed_by' => $r->confirmed_by, 'automatic' => (bool) $r->auto,
                ];
            }
        }

        if (Schema::hasTable('rental_receipts')) {
            $query = DB::table('rental_receipts as r')
                ->leftJoin('rental_customers as c', 'c.id', '=', 'r.customer_id')
                ->leftJoin('rental_invoices as i', 'i.id', '=', 'r.invoice_id')
                ->whereDate('r.date', '>=', $from)->whereDate('r.date', '<=', $to)
                ->orderBy('r.date')->orderBy('r.id')
                ->get(['r.reference', 'r.date', 'r.amount', 'r.method', 'c.name as customer', 'i.reference as invoice']);
            foreach ($query as $r) {
                $rows[] = [
                    'source' => 'rent-a-car receipt', 'reference' => $r->reference, 'date' => substr((string) $r->date, 0, 10),
                    'amount' => round((float) $r->amount, 3), 'method' => $r->method, 'customer' => $r->customer, 'invoice' => $r->invoice,
                    'confirmed' => null, 'confirmed_by' => null, 'automatic' => false,
                ];
            }
        }

        return $this->answer($from, $to, $rows);
    }

    public function out(Request $request): JsonResponse
    {
        $period = $this->period($request);
        if ($period instanceof JsonResponse) {
            return $period;
        }
        [$from, $to] = $period;

        $rows = [];

        if (Schema::hasTable('expense_payments')) {
            foreach (DB::table('expense_payments as p')->leftJoin('expenses as e', 'e.id', '=', 'p.expense_id')
                ->whereDate('p.paid_on', '>=', $from)->whereDate('p.paid_on', '<=', $to)->orderBy('p.paid_on')
                ->get(['p.name', 'p.period', 'p.amount', 'p.paid_on', 'e.category']) as $r) {
                $rows[] = ['source' => 'company expense', 'reference' => $r->name, 'date' => substr((string) $r->paid_on, 0, 10),
                    'amount' => round((float) $r->amount, 3), 'payee' => $r->name, 'category' => $r->category, 'status' => 'paid', 'note' => $r->period];
            }
        }

        if (Schema::hasTable('purchases')) {
            foreach (DB::table('purchases as p')->leftJoin('partners as v', 'v.id', '=', 'p.partner_id')
                ->whereDate('p.date', '>=', $from)->whereDate('p.date', '<=', $to)->orderBy('p.date')
                ->get(['p.reference', 'p.date', 'p.total', 'p.state', 'v.name as vendor']) as $r) {
                $rows[] = ['source' => 'purchase', 'reference' => $r->reference, 'date' => substr((string) $r->date, 0, 10),
                    'amount' => round((float) $r->total, 3), 'payee' => $r->vendor, 'category' => 'purchase', 'status' => $r->state, 'note' => null];
            }
        }

        if (Schema::hasTable('limo_expenses')) {
            foreach (DB::table('limo_expenses as x')->leftJoin('rental_drivers as d', 'd.id', '=', 'x.driver_id')
                ->whereDate('x.date', '>=', $from)->whereDate('x.date', '<=', $to)->orderBy('x.date')
                ->get(['x.reference', 'x.date', 'x.amount', 'x.category', 'x.payee', 'd.name as driver', 'x.bill_path']) as $r) {
                $rows[] = ['source' => 'limousine expense', 'reference' => $r->reference, 'date' => substr((string) $r->date, 0, 10),
                    'amount' => round((float) $r->amount, 3), 'payee' => $r->payee ?? $r->driver, 'category' => $r->category,
                    'status' => 'recorded', 'note' => $r->bill_path === null ? 'no bill attached' : null];
            }
        }

        if (Schema::hasTable('limo_petty_advances')) {
            foreach (DB::table('limo_petty_advances as a')->leftJoin('rental_drivers as d', 'd.id', '=', 'a.driver_id')
                ->whereDate('a.date', '>=', $from)->whereDate('a.date', '<=', $to)->orderBy('a.date')
                ->get(['a.reference', 'a.date', 'a.amount', 'a.status', 'a.shortfall', 'a.receipts_total', 'd.name as driver']) as $r) {
                $rows[] = ['source' => 'petty cash advance', 'reference' => $r->reference, 'date' => substr((string) $r->date, 0, 10),
                    'amount' => round((float) $r->amount, 3), 'payee' => $r->driver, 'category' => 'petty cash', 'status' => $r->status,
                    'note' => sprintf('receipts %.3f, shortfall %.3f', (float) $r->receipts_total, (float) $r->shortfall)];
            }
        }

        // Payroll as one total per month: never one person's salary.
        if (Schema::hasTable('payslips')) {
            foreach (DB::table('payslips')->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)
                ->selectRaw('period, count(*) as people, sum(net) as total, max(paid_on) as paid_on')->groupBy('period')->orderBy('period')->get() as $r) {
                $rows[] = ['source' => 'payroll', 'reference' => 'Payroll '.$r->period, 'date' => substr((string) $r->paid_on, 0, 10),
                    'amount' => round((float) $r->total, 3), 'payee' => $r->people.' staff', 'category' => 'salaries', 'status' => 'paid', 'note' => null];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

        return $this->answer($from, $to, $rows);
    }

    /**
     * @return array{0: string, 1: string}|JsonResponse
     */
    private function period(Request $request): array|JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        foreach (['from' => $from, 'to' => $to] as $key => $value) {
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                return response()->json(['error' => $key.' is required, written YYYY-MM-DD.'], 422);
            }
        }

        /** @var string $from */
        /** @var string $to */
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to), false);
        if ($days < 0 || $days > self::MAX_DAYS) {
            return response()->json(['error' => 'to must be on or after from, at most '.self::MAX_DAYS.' days later.'], 422);
        }

        return [$from, $to];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function answer(string $from, string $to, array $rows): JsonResponse
    {
        return response()->json([
            'from' => $from,
            'to' => $to,
            'total' => round(array_sum(array_map(static fn (array $row): float => (float) $row['amount'], $rows)), 3),
            'count' => count($rows),
            'data' => $rows,
        ]);
    }
}

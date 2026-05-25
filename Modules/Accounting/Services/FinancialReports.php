<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Models\Account;

/**
 * Single-pass SQL aggregates for the three core financial statements.
 *
 * Every method returns plain arrays / value-object-ish associative
 * structures — easy to feed straight into a Livewire view, a CSV
 * export, or an API endpoint. No formatting is done here; render the
 * `decimal` fields through `App\Erp\Money\Currencies::format()` at the
 * view layer (project convention).
 *
 * Only POSTED entries count — drafts are mid-edit and don't belong on
 * statements. Date filters apply to the parent entry's `date` column
 * (the bookkeeping date), not `created_at`.
 */
final class FinancialReports
{
    /**
     * Trial Balance — every account with its period debit/credit totals
     * and signed balance. Accounts with zero activity are omitted by
     * default (`$includeZero = false`) to keep the report scannable.
     *
     * @return list<array{
     *     account_id: int,
     *     code: string,
     *     name: string,
     *     type: AccountType,
     *     debit: float,
     *     credit: float,
     *     balance: float
     * }>
     */
    public function trialBalance(?Carbon $from = null, ?Carbon $to = null, bool $includeZero = false): array
    {
        $rows = DB::table('accounts')
            ->leftJoin('journal_items', 'journal_items.account_id', '=', 'accounts.id')
            ->leftJoin('journal_entries', function ($join) use ($from, $to): void {
                $join->on('journal_entries.id', '=', 'journal_items.journal_entry_id')
                    ->where('journal_entries.state', 'posted');

                if ($from !== null) {
                    $join->where('journal_entries.date', '>=', $from->toDateString());
                }

                if ($to !== null) {
                    $join->where('journal_entries.date', '<=', $to->toDateString());
                }
            })
            ->where('accounts.active', true)
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->get([
                'accounts.id as account_id',
                'accounts.code',
                'accounts.name',
                'accounts.type',
                DB::raw('COALESCE(SUM(journal_items.debit), 0) as debit'),
                DB::raw('COALESCE(SUM(journal_items.credit), 0) as credit'),
            ]);

        $out = [];
        foreach ($rows as $row) {
            $debit = round((float) $row->debit, 2);
            $credit = round((float) $row->credit, 2);

            if (! $includeZero && $debit === 0.0 && $credit === 0.0) {
                continue;
            }

            $type = AccountType::from((string) $row->type);

            $out[] = [
                'account_id' => (int) $row->account_id,
                'code' => (string) $row->code,
                'name' => $this->translatedName((string) $row->name),
                'type' => $type,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $type->signedBalance($debit, $credit),
            ];
        }

        return $out;
    }

    /**
     * Profit & Loss — income minus expense over the period.
     *
     * @return array{
     *     income: list<array{account_id:int, code:string, name:string, amount:float}>,
     *     expense: list<array{account_id:int, code:string, name:string, amount:float}>,
     *     total_income: float,
     *     total_expense: float,
     *     net_profit: float,
     *     period: array{from: string|null, to: string|null}
     * }
     */
    public function profitAndLoss(?Carbon $from = null, ?Carbon $to = null): array
    {
        $income = $this->groupByType(AccountType::Income, $from, $to);
        $expense = $this->groupByType(AccountType::Expense, $from, $to);

        $totalIncome = round(array_sum(array_column($income, 'amount')), 2);
        $totalExpense = round(array_sum(array_column($expense, 'amount')), 2);

        return [
            'income' => $income,
            'expense' => $expense,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_profit' => round($totalIncome - $totalExpense, 2),
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
        ];
    }

    /**
     * Balance Sheet — assets / liabilities / equity AS OF a point in time
     * (inclusive). Equity is reported in two pieces: the explicit equity
     * accounts plus the **net profit carried forward** (income – expense
     * up to the same date) — the accounting identity Assets = Liabilities
     * + Equity only holds with that retained-earnings piece included.
     *
     * @return array{
     *     as_of: string,
     *     assets: list<array{account_id:int, code:string, name:string, amount:float}>,
     *     liabilities: list<array{account_id:int, code:string, name:string, amount:float}>,
     *     equity: list<array{account_id:int, code:string, name:string, amount:float}>,
     *     total_assets: float,
     *     total_liabilities: float,
     *     total_equity: float,
     *     retained_earnings: float,
     *     is_balanced: bool
     * }
     */
    public function balanceSheet(?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? Carbon::now();

        $assets = $this->groupByType(AccountType::Asset, null, $asOf);
        $liabilities = $this->groupByType(AccountType::Liability, null, $asOf);
        $equity = $this->groupByType(AccountType::Equity, null, $asOf);

        // Retained earnings = cumulative net profit from beginning of
        // time through `as_of`. Without this the BS won't balance after
        // the first P&L period closes.
        $pl = $this->profitAndLoss(null, $asOf);
        $retainedEarnings = $pl['net_profit'];

        $totalAssets = round(array_sum(array_column($assets, 'amount')), 2);
        $totalLiabilities = round(array_sum(array_column($liabilities, 'amount')), 2);
        $totalEquity = round(array_sum(array_column($equity, 'amount')) + $retainedEarnings, 2);

        return [
            'as_of' => $asOf->toDateString(),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'retained_earnings' => $retainedEarnings,
            'is_balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.005,
        ];
    }

    /**
     * Per-account ledger lines for one account in date order — the
     * "drill down" from a trial balance row. Returns rows with a
     * running balance so the view can render a classic ledger.
     *
     * @return list<array{
     *     entry_id: int,
     *     entry_number: string,
     *     date: string,
     *     reference: string|null,
     *     memo: string|null,
     *     debit: float,
     *     credit: float,
     *     running_balance: float
     * }>
     */
    public function accountLedger(Account $account, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = DB::table('journal_items')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_items.journal_entry_id')
            ->where('journal_items.account_id', $account->id)
            ->where('journal_entries.state', 'posted')
            ->when($from !== null, fn ($q) => $q->where('journal_entries.date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->where('journal_entries.date', '<=', $to->toDateString()))
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_items.id')
            ->get([
                'journal_entries.id as entry_id',
                'journal_entries.number as entry_number',
                'journal_entries.date',
                'journal_entries.reference',
                'journal_items.memo',
                'journal_items.debit',
                'journal_items.credit',
            ]);

        $out = [];
        $running = 0.0;
        $sign = $account->type->normalBalance() === 'debit' ? 1.0 : -1.0;

        foreach ($rows as $row) {
            $debit = round((float) $row->debit, 2);
            $credit = round((float) $row->credit, 2);
            $running = round($running + ($debit - $credit) * $sign, 2);

            $out[] = [
                'entry_id' => (int) $row->entry_id,
                'entry_number' => (string) $row->entry_number,
                'date' => (string) $row->date,
                'reference' => $row->reference !== null ? (string) $row->reference : null,
                'memo' => $row->memo !== null ? (string) $row->memo : null,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
            ];
        }

        return $out;
    }

    /**
     * One row per account of the requested type, with the signed balance
     * over the date window — the shared building block of P&L and BS.
     *
     * @return list<array{account_id:int, code:string, name:string, amount:float}>
     */
    private function groupByType(AccountType $type, ?Carbon $from, ?Carbon $to): array
    {
        $rows = DB::table('accounts')
            ->leftJoin('journal_items', 'journal_items.account_id', '=', 'accounts.id')
            ->leftJoin('journal_entries', function ($join) use ($from, $to): void {
                $join->on('journal_entries.id', '=', 'journal_items.journal_entry_id')
                    ->where('journal_entries.state', 'posted');

                if ($from !== null) {
                    $join->where('journal_entries.date', '>=', $from->toDateString());
                }

                if ($to !== null) {
                    $join->where('journal_entries.date', '<=', $to->toDateString());
                }
            })
            ->where('accounts.type', $type->value)
            ->where('accounts.active', true)
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name')
            ->orderBy('accounts.code')
            ->get([
                'accounts.id as account_id',
                'accounts.code',
                'accounts.name',
                DB::raw('COALESCE(SUM(journal_items.debit), 0) as debit'),
                DB::raw('COALESCE(SUM(journal_items.credit), 0) as credit'),
            ]);

        $out = [];
        foreach ($rows as $row) {
            $amount = $type->signedBalance(
                round((float) $row->debit, 2),
                round((float) $row->credit, 2),
            );

            if ($amount === 0.0) {
                continue;
            }

            $out[] = [
                'account_id' => (int) $row->account_id,
                'code' => (string) $row->code,
                'name' => $this->translatedName((string) $row->name),
                'amount' => $amount,
            ];
        }

        return $out;
    }

    /**
     * `accounts.name` is a translatable JSON envelope (`{"en":..., "ar":...}`).
     * The DB query above pulls the raw column — decode here for the active
     * locale, falling back to the raw string if it isn't JSON (e.g. seed
     * rows written as plain strings prior to opting the column in).
     */
    private function translatedName(string $raw): string
    {
        if ($raw === '' || $raw[0] !== '{') {
            return $raw;
        }

        /** @var array<string, string>|null $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $raw;
        }

        $locale = app()->getLocale();

        if (isset($decoded[$locale]) && is_string($decoded[$locale])) {
            return $decoded[$locale];
        }

        if (isset($decoded['en']) && is_string($decoded['en'])) {
            return $decoded['en'];
        }

        $first = reset($decoded);
        return is_string($first) ? $first : $raw;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

/**
 * The five primary account classes from the accounting equation
 * (Assets = Liabilities + Equity, with Income/Expense flowing into Equity).
 *
 * `normalBalance()` answers the bookkeeping convention question: does
 * this account increase on the debit or the credit side? That single
 * fact drives every balance, statement, and posting rule downstream.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => __('Asset'),
            self::Liability => __('Liability'),
            self::Equity => __('Equity'),
            self::Income => __('Income'),
            self::Expense => __('Expense'),
        };
    }

    /**
     * 'debit' = account grows on the debit side (assets, expenses).
     * 'credit' = account grows on the credit side (liabilities, equity, income).
     *
     * @return 'debit'|'credit'
     */
    public function normalBalance(): string
    {
        return match ($this) {
            self::Asset, self::Expense => 'debit',
            self::Liability, self::Equity, self::Income => 'credit',
        };
    }

    /**
     * Convert a (debit, credit) pair into the signed balance under this
     * account's normal-balance convention — positive means "more of what
     * this account naturally holds", negative means contra.
     */
    public function signedBalance(float $debit, float $credit): float
    {
        return $this->normalBalance() === 'debit'
            ? round($debit - $credit, 2)
            : round($credit - $debit, 2);
    }

    /**
     * Income & expense reset every period — they roll up into the P&L.
     * Asset / liability / equity carry forward — they roll up into the
     * balance sheet. `FinancialReports` uses this to split the COA cleanly.
     */
    public function isProfitAndLoss(): bool
    {
        return $this === self::Income || $this === self::Expense;
    }

    public function isBalanceSheet(): bool
    {
        return ! $this->isProfitAndLoss();
    }
}

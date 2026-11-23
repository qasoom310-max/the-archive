<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Models\LimoPettyCategory;
use Modules\Limousine\Models\LimoPettyLine;
use Modules\Limousine\Models\LimoPettyAdvance;
use Modules\Limousine\Models\LimoPettyTopup;

/**
 * The petty-cash rules, in one place.
 *
 * The float's balance is DERIVED: top-ups in, advances out the moment they are
 * issued (the cash left the drawer then, not when the paperwork caught up),
 * and excess reimbursements out at settlement. A stored balance and the rows
 * it summarises always eventually disagree.
 */
final class PettyCash
{
    /**
     * Who may run the desk — top up the float, send cash to a driver, import,
     * add a spending category: an administrator, the supervisor accountant
     * (the Accountant role), or someone granted the desk in full
     * ({@see User::hasFullPettyCash()}). Write on petty cash is required on
     * top, so a scoped administrator stays in their apps.
     */
    public static function mayManage(?Authenticatable $user): bool
    {
        return $user instanceof User
            && ($user->isAdmin() || $user->isAccountant() || $user->hasFullPettyCash())
            && app(AccessControl::class)->allows($user, 'limousine.petty_cash', Permission::Write);
    }

    /**
     * Who may confirm a hand-over and settle an advance: the accountant or a
     * super admin (the payment-confirmation power), or someone granted the
     * desk in full.
     */
    public static function mayConfirm(?Authenticatable $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->canConfirmPayments()
            || ($user->hasFullPettyCash()
                && app(AccessControl::class)->allows($user, 'limousine.petty_cash', Permission::Write));
    }

    public function floatBalance(): float
    {
        return round(
            (float) LimoPettyTopup::query()->sum('amount')
            - (float) LimoPettyAdvance::query()->sum('amount')
            - (float) LimoPettyAdvance::query()->sum('excess'),
            3,
        );
    }

    /**
     * Where the float balance came from: every movement in a period, oldest
     * first, with the balance after each one.
     *
     * Three kinds of movement, the same three {@see floatBalance()} adds up:
     * a top-up (in, on its date), an advance (out, on the day it was handed
     * over) and an overspend paid back to a driver (out, when the advance was
     * settled). A shortfall is not a movement — the float never gets it back.
     * Null bounds read all time; the opening balance is everything before
     * $from, so opening + in − out always equals the closing balance.
     *
     * @return array{
     *     opening: float, closing: float, in: float, out: float,
     *     rows: list<array{date: string, kind: string, reference: ?string, advance_id: ?int, driver: ?string, by: ?string, notes: ?string, in: float, out: float, balance: float}>,
     *     totals: array{topups: float, topup_count: int, given: float, given_count: int, paid_back: float, paid_back_count: int, short: float, short_count: int}
     * }
     */
    public function statement(?Carbon $from = null, ?Carbon $to = null): array
    {
        // A date column is "Y-m-d" on MySQL and "Y-m-d 00:00:00" on SQLite,
        // compared as text: a date lower bound and a datetime upper bound
        // catch both.
        $within = static function ($query, string $column, bool $isDate) use ($from, $to) {
            if ($from !== null) {
                $query->where($column, '>=', $isDate ? $from->toDateString() : $from->toDateTimeString());
            }
            if ($to !== null) {
                $query->where($column, '<=', (clone $to)->endOfDay()->toDateTimeString());
            }

            return $query;
        };

        $opening = 0.0;
        if ($from !== null) {
            $opening = round(
                (float) LimoPettyTopup::query()->where('date', '<', $from->toDateString())->sum('amount')
                - (float) LimoPettyAdvance::query()->where('date', '<', $from->toDateString())->sum('amount')
                - (float) LimoPettyAdvance::query()->where('settled_at', '<', $from->toDateTimeString())->sum('excess'),
                3,
            );
        }

        $rows = [];

        foreach ($within(LimoPettyTopup::query(), 'date', true)->get() as $topup) {
            $rows[] = [
                'sort' => ($topup->date?->toDateString() ?? '') . ' 0 ' . sprintf('%010d', $topup->id),
                'date' => (string) $topup->date?->toDateString(),
                'kind' => 'topup', 'reference' => null, 'advance_id' => null, 'driver' => null,
                'by' => $topup->added_by, 'notes' => $topup->notes,
                'in' => (float) $topup->amount, 'out' => 0.0,
            ];
        }

        foreach ($within(LimoPettyAdvance::query()->with('driver:id,name'), 'date', true)->get() as $advance) {
            $rows[] = [
                'sort' => ($advance->date?->toDateString() ?? '') . ' 1 ' . sprintf('%010d', $advance->id),
                'date' => (string) $advance->date?->toDateString(),
                'kind' => 'advance', 'reference' => $advance->reference, 'advance_id' => $advance->id,
                'driver' => $advance->driver?->name, 'by' => $advance->issued_by, 'notes' => $advance->notes,
                'in' => 0.0, 'out' => (float) $advance->amount,
            ];
        }

        $paidBack = $within(LimoPettyAdvance::query()->with('driver:id,name')->where('excess', '>', 0), 'settled_at', false)->get();
        foreach ($paidBack as $advance) {
            $rows[] = [
                'sort' => ($advance->settled_at?->toDateString() ?? '') . ' 2 ' . sprintf('%010d', $advance->id),
                'date' => (string) $advance->settled_at?->toDateString(),
                'kind' => 'paid_back', 'reference' => $advance->reference, 'advance_id' => $advance->id,
                'driver' => $advance->driver?->name, 'by' => $advance->settled_by, 'notes' => null,
                'in' => 0.0, 'out' => (float) $advance->excess,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));

        $balance = $opening;
        $in = 0.0;
        $out = 0.0;
        $listed = [];
        foreach ($rows as $row) {
            unset($row['sort']);
            $balance = round($balance + $row['in'] - $row['out'], 3);
            $in += $row['in'];
            $out += $row['out'];
            $row['balance'] = $balance;
            $listed[] = $row;
        }

        $short = LimoPettyAdvance::query()->where('shortfall', '>', 0);

        return [
            'opening' => $opening,
            'closing' => $balance,
            'in' => round($in, 3),
            'out' => round($out, 3),
            'rows' => $listed,
            'totals' => [
                'topups' => round((float) LimoPettyTopup::query()->sum('amount'), 3),
                'topup_count' => LimoPettyTopup::query()->count(),
                'given' => round((float) LimoPettyAdvance::query()->sum('amount'), 3),
                'given_count' => LimoPettyAdvance::query()->count(),
                'paid_back' => round((float) LimoPettyAdvance::query()->sum('excess'), 3),
                'paid_back_count' => LimoPettyAdvance::query()->where('excess', '>', 0)->count(),
                'short' => round((float) (clone $short)->sum('shortfall'), 3),
                'short_count' => (clone $short)->count(),
            ],
        ];
    }

    /**
     * Cash the drivers still hold: what was given on advances not yet closed,
     * less the bills already counted on them. A bill comes off the moment it
     * is saved; no confirm or settle is needed for this figure (the owner's
     * rule, 2026-11-23).
     */
    public function outstanding(): float
    {
        $open = LimoPettyAdvance::query()->where('status', '!=', LimoPettyAdvance::STATUS_CLEARED);
        $given = (float) (clone $open)->sum('amount');
        $bills = (float) LimoPettyLine::query()->whereIn('advance_id', (clone $open)->select('id'))->sum('amount');

        return round($given - $bills, 3);
    }

    /**
     * A bill saved for a driver comes off the cash he holds by itself: it goes
     * on his OLDEST open advance. Nothing happens when he holds no open cash,
     * or the bill is already on a petty cash.
     */
    public function countForDriver(LimoExpense $expense): ?LimoPettyLine
    {
        if ($expense->driver_id === null) {
            return null;
        }

        $advance = LimoPettyAdvance::query()
            ->where('driver_id', $expense->driver_id)
            ->where('status', '!=', LimoPettyAdvance::STATUS_CLEARED)
            ->orderBy('date')
            ->orderBy('id')
            ->first();

        return $advance !== null ? $this->addExpenseAsReceipt($advance, $expense) : null;
    }

    public function topUp(float $amount, string $date, ?string $notes, string $byName): LimoPettyTopup
    {
        return LimoPettyTopup::query()->create([
            'date' => $date, 'amount' => round($amount, 3),
            'added_by' => $byName, 'notes' => $notes,
        ]);
    }

    /**
     * Hand a driver money from the float.
     *
     * Refused when the float cannot cover it: issuing cash that is not in the
     * drawer is not a rounding matter, it means the balance here and the money
     * in the manager's hands have already parted ways.
     */
    public function issue(LimoDriver $driver, float $amount, string $date, ?string $notes, string $byName): ?LimoPettyAdvance
    {
        $amount = round($amount, 3);

        if ($amount <= 0.0 || $amount > $this->floatBalance() + 0.0005) {
            return null;
        }

        return LimoPettyAdvance::query()->create([
            'driver_id' => $driver->id,
            'date' => $date,
            'amount' => $amount,
            'status' => LimoPettyAdvance::STATUS_ISSUED,
            'issued_by' => $byName,
            'notes' => $notes,
        ]);
    }

    /** The accountant vouches the hand-over really happened. */
    public function confirm(LimoPettyAdvance $advance, string $byName): void
    {
        if ($advance->status !== LimoPettyAdvance::STATUS_ISSUED) {
            return;
        }

        $advance->status = LimoPettyAdvance::STATUS_CONFIRMED;
        $advance->confirmed_at = Carbon::now();
        $advance->confirmed_by = $byName;
        $advance->save();
    }

    /**
     * Close the advance against the paper the driver handed in.
     *
     * Receipts under the amount → the difference is recorded as SHORT and
     * nothing more: it is never deducted from the driver's salary (the owner's
     * decision, 2026-10-27). Receipts over it → the difference is REIMBURSED from the float
     * (the standing rule, chosen over deciding each time). Every receipt line
     * is also written into the expense ledger, so the expense reports read the
     * same money the settlement did — one truth, not a second book.
     *
     * @return array{receipts_total: float, shortfall: float, excess: float}|null
     */
    public function settle(LimoPettyAdvance $advance, string $byName): ?array
    {
        if ($advance->status !== LimoPettyAdvance::STATUS_CONFIRMED) {
            return null;
        }

        return DB::transaction(function () use ($advance, $byName): array {
            $total = $advance->linesTotal();
            $shortfall = round(max(0.0, (float) $advance->amount - $total), 3);
            $excess = round(max(0.0, $total - (float) $advance->amount), 3);

            $advance->receipts_total = $total;
            $advance->shortfall = $shortfall;
            $advance->excess = $excess;
            $advance->status = LimoPettyAdvance::STATUS_CLEARED;
            $advance->settled_at = Carbon::now();
            $advance->settled_by = $byName;
            $advance->save();

            $driverName = (string) ($advance->driver->name ?? '');

            foreach ($advance->lines as $line) {
                // Scanned in Expenses already, against this driver's cash:
                // the expense exists, so it is not written a second time.
                if ($line->expense_id !== null && LimoExpense::query()->whereKey($line->expense_id)->exists()) {
                    continue;
                }

                // The line stores the category's NAME (the owner's list); the
                // ledger has fixed slots. An owner-invented category files
                // under "other" but keeps its name in the notes, so nothing
                // is lost in translation.
                $slot = \Modules\Limousine\Models\LimoPettyCategory::expenseSlotFor($line->category);

                $written = new LimoExpense();
                // Already a receipt on this advance: not counted a second time.
                $written->countOnPettyCash = false;
                $written->fill([
                    'date' => $line->date,
                    'category' => $slot,
                    'amount' => $line->amount,
                    'payee' => $driverName,
                    // Whose receipts these are, for the Driver column in Expenses.
                    'driver_id' => $advance->driver_id,
                    'notes' => trim(
                        $advance->reference
                        . ($slot === 'other' ? ' — ' . $line->category : '')
                        . ($line->vehicle !== null && $line->vehicle !== '' ? ' — ' . $line->vehicle : '')
                        . ' — ' . (string) $line->description,
                        ' —',
                    ),
                ]);
                $written->save();

                // Remembered on the receipt, so it is never offered or written again.
                $line->forceFill(['expense_id' => $written->id])->saveQuietly();
            }

            return ['receipts_total' => $total, 'shortfall' => $shortfall, 'excess' => $excess];
        });
    }

    /**
     * Put an expense already in Expenses on a driver's advance as one of his
     * receipts. Only HIS advance, only while it is open, and only an expense
     * not already on any petty cash, so a receipt can never count twice.
     */
    public function addExpenseAsReceipt(LimoPettyAdvance $advance, LimoExpense $expense): ?LimoPettyLine
    {
        if ($advance->isCleared()
            || $expense->driver_id === null
            || (int) $expense->driver_id !== (int) $advance->driver_id
            || LimoPettyLine::query()->where('expense_id', $expense->id)->exists()) {
            return null;
        }

        $category = LimoPettyCategory::query()->where('expense_category', $expense->category)->value('name');
        if (! is_string($category) || $category === '') {
            $labels = array_column(LimoExpense::categoryOptions(), 'label', 'value');
            $category = (string) ($labels[$expense->category] ?? 'Other');
        }

        return $advance->lines()->create([
            'date' => $expense->date?->toDateString() ?? Carbon::now()->toDateString(),
            'category' => $category,
            'description' => trim((string) ($expense->payee ?? '') . ' · ' . (string) $expense->reference, ' ·'),
            'amount' => round((float) $expense->amount, 3),
            'expense_id' => $expense->id,
        ]);
    }

    /**
     * The driver's expenses not on any petty cash yet: what can still be added
     * to his advance. From a month before the cash was given, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, LimoExpense>
     */
    public function unlinkedExpensesFor(LimoPettyAdvance $advance): \Illuminate\Database\Eloquent\Collection
    {
        if ($advance->isCleared() || $advance->driver_id === null) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return LimoExpense::query()
            ->where('driver_id', $advance->driver_id)
            ->whereNotIn('id', LimoPettyLine::query()->whereNotNull('expense_id')->select('expense_id'))
            ->when($advance->date !== null, fn ($q) => $q->whereDate('date', '>=', $advance->date->copy()->subMonth()->toDateString()))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * One driver's cash, for the audit: every movement in a period with the
     * balance after each, and the opening balance from everything before.
     *
     *   + cash given        an advance, on its date
     *   − bill              a receipt on one of his advances, on the bill's date,
     *                       with the expense it was saved as (VAT, who received
     *                       the original paper and when)
     *   + paid back         overspend the float paid back to him, when settled
     *   − written off short what was left unaccounted when settled
     *
     * Settling is not needed for any of it: a bill counts the day it is saved.
     * The closing balance is what he still holds (below zero: we owe him).
     *
     * @return array{
     *     opening: float, closing: float, in: float, out: float,
     *     rows: list<array{date: string, kind: string, reference: string, advance_id: int|null, detail: string, expense: string, expense_id: int|null, vat: float, original: string, in: float, out: float, balance: float}>,
     *     rejected: list<array{date: string, trip: string, reason: string, told: string}>
     * }
     */
    public function driverStatement(int $driverId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $advances = LimoPettyAdvance::query()->where('driver_id', $driverId)->get();
        $advanceIds = $advances->pluck('id')->all();
        $lines = LimoPettyLine::query()->whereIn('advance_id', $advanceIds === [] ? [0] : $advanceIds)
            ->with('expense:id,reference,vat_amount,original_received_at,original_received_by')->get();
        $refs = $advances->pluck('reference', 'id');

        $moves = [];
        foreach ($advances as $a) {
            $moves[] = ['date' => $a->date?->toDateString() ?? '', 'order' => 1, 'kind' => 'given', 'reference' => (string) $a->reference, 'advance_id' => (int) $a->id,
                'detail' => trim(__('Given by :name', ['name' => (string) ($a->issued_by ?? '—')]) . ' ' . (string) ($a->notes ?? '')),
                'expense' => '', 'expense_id' => null, 'vat' => 0.0, 'original' => '', 'in' => (float) $a->amount, 'out' => 0.0];

            if ($a->isCleared() && $a->settled_at !== null) {
                if ((float) $a->excess > 0.0005) {
                    $moves[] = ['date' => $a->settled_at->toDateString(), 'order' => 3, 'kind' => 'paid_back', 'reference' => (string) $a->reference, 'advance_id' => (int) $a->id,
                        'detail' => __('Overspend paid back to him'), 'expense' => '', 'expense_id' => null, 'vat' => 0.0, 'original' => '', 'in' => (float) $a->excess, 'out' => 0.0];
                }
                if ((float) $a->shortfall > 0.0005) {
                    $moves[] = ['date' => $a->settled_at->toDateString(), 'order' => 4, 'kind' => 'short', 'reference' => (string) $a->reference, 'advance_id' => (int) $a->id,
                        'detail' => __('Short when settled (not deducted from salary)'), 'expense' => '', 'expense_id' => null, 'vat' => 0.0, 'original' => '', 'in' => 0.0, 'out' => (float) $a->shortfall];
                }
            }
        }

        foreach ($lines as $line) {
            $expense = $line->expense;
            $original = '';
            if ($expense?->original_received_at !== null) {
                $original = trim((string) $expense->original_received_by . ' · ' . $expense->original_received_at->format('Y-m-d'), ' ·');
            }
            $moves[] = ['date' => $line->date?->toDateString() ?? '', 'order' => 2, 'kind' => 'bill', 'reference' => (string) ($refs[$line->advance_id] ?? ''), 'advance_id' => (int) $line->advance_id,
                'detail' => trim((string) $line->category . ' · ' . (string) ($line->description ?? ''), ' ·'),
                'expense' => (string) ($expense->reference ?? ''), 'expense_id' => $expense?->id !== null ? (int) $expense->id : null,
                'vat' => round((float) ($expense->vat_amount ?? 0), 3), 'original' => $original, 'in' => 0.0, 'out' => (float) $line->amount];
        }

        usort($moves, static fn (array $a, array $b): int => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

        $fromDay = $from?->toDateString();
        $toDay = $to?->toDateString();
        $opening = 0.0;
        $rows = [];
        $in = 0.0;
        $out = 0.0;
        $balance = 0.0;

        foreach ($moves as $m) {
            if ($fromDay !== null && $m['date'] < $fromDay) {
                $opening = round($opening + $m['in'] - $m['out'], 3);
                $balance = $opening;

                continue;
            }
            if ($toDay !== null && $m['date'] > $toDay) {
                continue;
            }

            $balance = round($balance + $m['in'] - $m['out'], 3);
            $in = round($in + $m['in'], 3);
            $out = round($out + $m['out'], 3);
            unset($m['order']);
            $rows[] = $m + ['balance' => $balance];
        }

        $rejected = [];
        if (\Illuminate\Support\Facades\Schema::hasColumn('limo_driver_bills', 'reject_reason')) {
            $rejected = \Modules\Limousine\Models\LimoDriverBill::query()
                ->where('driver_id', $driverId)
                ->where('status', \Modules\Limousine\Models\LimoDriverBill::STATUS_REJECTED)
                ->when($from !== null, fn ($q) => $q->where('handled_at', '>=', $from->copy()->startOfDay()))
                ->when($to !== null, fn ($q) => $q->where('handled_at', '<=', $to->copy()->endOfDay()))
                ->with('leg:id,reference')->orderBy('handled_at')->get()
                ->map(static fn (\Modules\Limousine\Models\LimoDriverBill $b): array => [
                    'date' => $b->handled_at?->toDateString() ?? '',
                    'trip' => (string) ($b->leg->reference ?? ''),
                    'reason' => (string) $b->reject_reason,
                    'told' => $b->told_at !== null ? trim((string) $b->told_by . ' · ' . $b->told_at->format('Y-m-d'), ' ·') : '',
                ])->values()->all();
        }

        return [
            'opening' => $opening,
            'closing' => round($opening + $in - $out, 3),
            'in' => $in,
            'out' => $out,
            'rows' => $rows,
            'rejected' => $rejected,
        ];
    }
}

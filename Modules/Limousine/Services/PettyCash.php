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

    /** Money handed out but not yet settled — the float's exposure. */
    public function outstanding(): float
    {
        return round((float) LimoPettyAdvance::query()
            ->where('status', '!=', LimoPettyAdvance::STATUS_CLEARED)
            ->sum('amount'), 3);
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
                // The line stores the category's NAME (the owner's list); the
                // ledger has fixed slots. An owner-invented category files
                // under "other" but keeps its name in the notes, so nothing
                // is lost in translation.
                $slot = \Modules\Limousine\Models\LimoPettyCategory::expenseSlotFor($line->category);

                LimoExpense::query()->create([
                    'date' => $line->date,
                    'category' => $slot,
                    'amount' => $line->amount,
                    'payee' => $driverName,
                    'notes' => trim(
                        $advance->reference
                        . ($slot === 'other' ? ' — ' . $line->category : '')
                        . ($line->vehicle !== null && $line->vehicle !== '' ? ' — ' . $line->vehicle : '')
                        . ' — ' . (string) $line->description,
                        ' —',
                    ),
                ]);
            }

            return ['receipts_total' => $total, 'shortfall' => $shortfall, 'excess' => $excess];
        });
    }
}

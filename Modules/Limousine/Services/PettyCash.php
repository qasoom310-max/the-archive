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
     * Receipts under the amount → the difference is a SALARY DEDUCTION on the
     * driver. Receipts over it → the difference is REIMBURSED from the float
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

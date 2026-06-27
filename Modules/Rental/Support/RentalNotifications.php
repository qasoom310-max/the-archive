<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use App\Erp\Notifications\NotificationItem;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;

/**
 * The car-rental module's contribution to the top-bar notification bell. Each
 * alert is role-gated and derived live, so it disappears once acted on.
 */
final class RentalNotifications
{
    /**
     * @return list<NotificationItem>
     */
    public function for(Authenticatable $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $items = [];

        // Managers — maintenance work orders awaiting approval, and cars whose
        // registration / insurance need renewing.
        if ($user->canApproveMaintenance()) {
            $pending = RentalMaintenance::query()
                ->where('status', RentalMaintenance::STATUS_PENDING)
                ->get(['id', 'priority']);
            if ($pending->isNotEmpty()) {
                $critical = $pending->contains(fn (RentalMaintenance $m): bool => $m->priority === RentalMaintenance::PRIORITY_CRITICAL);
                $items[] = new NotificationItem(
                    title: __('Work orders awaiting approval'),
                    description: __(':count awaiting your approval', ['count' => $pending->count()]),
                    url: url('/app/rental/maintenance?tab=pending'),
                    level: $critical ? 'critical' : 'warning',
                    group: __('Maintenance'),
                );
            }

            $horizon = now()->addDays(Vehicle::RENEWAL_REMINDER_DAYS)->toDateString();
            $cars = Vehicle::query()
                ->where('active', true)
                ->where(function ($q) use ($horizon): void {
                    $q->whereNull('registration_expiry')
                        ->orWhereNull('insurance_expiry')
                        ->orWhereDate('registration_expiry', '<=', $horizon)
                        ->orWhereDate('insurance_expiry', '<=', $horizon);
                })
                ->get(['id', 'registration_expiry', 'insurance_expiry']);
            if ($cars->isNotEmpty()) {
                $expired = $cars->contains(fn (Vehicle $v): bool => $v->needsRenewal());
                $items[] = new NotificationItem(
                    title: __('Cars need paper renewal'),
                    description: __(':count expired or expiring soon', ['count' => $cars->count()]),
                    url: url('/app/rental'),
                    level: $expired ? 'critical' : 'warning',
                    group: __('Cars'),
                );
            }
        }

        // Accountants / super-admin — deposits past their hold, ready to refund.
        if ($user->canConfirmPayments()) {
            $due = RentalOrder::query()
                ->where('deposit', '>', 0)
                ->where('deposit_status', RentalOrder::DEPOSIT_HELD)
                ->whereNotNull('returned_at')
                ->whereDate('returned_at', '<=', now()->subDays(RentalOrder::DEPOSIT_HOLD_DAYS)->toDateString())
                ->count();
            if ($due > 0) {
                $items[] = new NotificationItem(
                    title: __('Deposits to refund'),
                    description: __(':count ready to refund', ['count' => $due]),
                    url: url('/app/rental'),
                    level: 'warning',
                    group: __('Deposits'),
                );
            }

            // Held deposits whose return flagged damage / extra / fuel charges —
            // the accountant should review a possible deduction (surfaced as soon
            // as it's recorded, not only once the hold elapses).
            $flagged = RentalOrder::query()
                ->where('deposit', '>', 0)
                ->where('deposit_status', RentalOrder::DEPOSIT_HELD)
                ->whereNotNull('returned_at')
                ->where(function ($q): void {
                    $q->where('has_damage', true)
                        ->orWhere('extra_charge', '>', 0)
                        ->orWhere('fuel_charge', '>', 0);
                })
                ->count();
            if ($flagged > 0) {
                $items[] = new NotificationItem(
                    title: __('Deposits with damage / charges'),
                    description: __(':count to review before refund', ['count' => $flagged]),
                    url: url('/app/rental'),
                    level: 'warning',
                    group: __('Deposits'),
                );
            }
        }

        // The requester — the outcome (approved / declined) of work orders they
        // raised, for 30 days after they're decided.
        $mine = RentalMaintenance::query()
            ->where('requested_by_user_id', $user->getKey())
            ->whereIn('status', [RentalMaintenance::STATUS_APPROVED, RentalMaintenance::STATUS_DECLINED])
            ->whereDate('created_at', '>=', now()->subDays(30)->toDateString())
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'reference', 'status']);
        foreach ($mine as $m) {
            $approved = $m->status === RentalMaintenance::STATUS_APPROVED;
            $items[] = new NotificationItem(
                title: $approved ? __('Your work order was approved') : __('Your work order was declined'),
                description: (string) $m->reference,
                url: url('/app/rental/maintenance/' . $m->id),
                level: $approved ? 'info' : 'warning',
                group: __('Your requests'),
            );
        }

        return $items;
    }
}

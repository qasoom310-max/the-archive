<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalOrder;

/**
 * Every security deposit the office still holds, in the order the money will
 * have to go back: the ones due now, the ones still inside the hold, and the
 * ones on cars that are not back yet.
 *
 * The page only lists. A deposit is settled on its order, where the amount cut,
 * the reason and the photos are recorded, so each row opens the order. The rule
 * on who may settle before the hold ends is the order's and is not repeated
 * here; this page only tells the viewer which button they will find.
 */
#[Layout('components.layouts.app')]
#[Title('Deposit refunds')]
final class DepositRefunds extends Component
{
    use GuardsModelAccess;

    protected function accessModelKey(): string
    {
        return 'rental.order';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
        // The same people who see the "Deposits to refund" box on the home page.
        abort_unless($this->viewer()?->canConfirmPayments() === true, 403);
    }

    private function viewer(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function render(): View
    {
        $today = Carbon::now()->startOfDay();
        // Same day rule as the home page box, so the two never disagree.
        $dueLine = Carbon::now()->subDays(RentalOrder::DEPOSIT_HOLD_DAYS)->toDateString();

        $orders = RentalOrder::query()
            ->where('deposit', '>', 0)
            ->where('deposit_status', RentalOrder::DEPOSIT_HELD)
            ->where(function ($q): void {
                // Back already, or still out on a running order. A draft or a
                // cancelled order that never went out holds no money to return.
                $q->whereNotNull('returned_at')
                    ->orWhere('state', RentalOrder::STATE_ACTIVE);
            })
            ->with('customer:id,name', 'vehicle:id,name,plate_no,color')
            ->get();

        $due = [];
        $coming = [];
        $out = [];

        foreach ($orders as $order) {
            $returned = $order->returned_at;
            $row = [
                'order' => $order,
                'date' => null,
                'days' => null,
                'fines' => $order->finesCheckPending(),
                'charges' => $order->has_damage || $order->extra_charge > 0 || $order->fuel_charge > 0,
            ];

            if ($returned === null) {
                // Not back yet: the hold starts on the day it is due back.
                $date = $order->end_date?->copy()->addDays(RentalOrder::DEPOSIT_HOLD_DAYS);
                $row['date'] = $date;
                $row['days'] = $date !== null ? (int) $today->diffInDays($date->copy()->startOfDay(), false) : null;
                $out[] = $row;

                continue;
            }

            $until = $returned->copy()->addDays(RentalOrder::DEPOSIT_HOLD_DAYS);
            $row['date'] = $until;
            $row['days'] = (int) $today->diffInDays($until->copy()->startOfDay(), false);

            if ($returned->toDateString() <= $dueLine) {
                $due[] = $row;
            } else {
                $coming[] = $row;
            }
        }

        $byDate = static fn (array $a, array $b): int => ($a['date']?->getTimestamp() ?? PHP_INT_MAX) <=> ($b['date']?->getTimestamp() ?? PHP_INT_MAX);
        usort($due, $byDate);
        usort($coming, $byDate);
        usort($out, $byDate);

        $sum = static fn (array $rows): float => (float) array_sum(array_map(
            static fn (array $r): float => $r['order']->deposit,
            $rows,
        ));

        return view('rental::deposit-refunds', [
            'groups' => [
                'due' => ['rows' => $due, 'total' => $sum($due)],
                'coming' => ['rows' => $coming, 'total' => $sum($coming)],
                'out' => ['rows' => $out, 'total' => $sum($out)],
            ],
            'holdDays' => RentalOrder::DEPOSIT_HOLD_DAYS,
            'canRefundEarly' => $this->viewer()?->isSuperAdmin() === true,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Enums\PrepStation;
use Modules\Pos\Enums\PrepStatus;
use Modules\Pos\Models\PosOrderLine;
use Modules\Pos\Support\KitchenTicket;

/**
 * Kitchen Display Screen — one component, parameterised by {@see $station}.
 * The same code/view runs at `/app/pos/kitchen/kitchen` (food) and
 * `/app/pos/kitchen/shisha` (shisha). The station prop scopes every query
 * to lines whose product → category → station matches.
 *
 * UI is a 3-column kanban (Pending / Preparing / Ready). Each card =
 * one order's lines for this station; the station never sees lines
 * routed to the OTHER station, so a single bill can produce two
 * different tickets on two different screens.
 *
 * Polling: `wire:poll.5s` on the view re-runs `render()` every 5s. The
 * page payload includes a sorted list of ticket ids; the Alpine wrapper
 * compares against the previous list and plays a Web Audio beep when
 * a new id appears — no audio file shipped, no autoplay headaches.
 *
 * Touch-friendly: large tap targets (min h-12), high-contrast status
 * stripes, no drag-drop (single-tap "Start preparing" / "Mark ready" /
 * "Complete" button drives the state machine).
 */
#[Layout('components.layouts.app')]
#[Title('Kitchen Display')]
final class KitchenDisplay extends Component
{
    public PrepStation $station;

    /**
     * Livewire/Laravel resolves the `{station}` route segment via the typed
     * property below — the string from the URL is converted to `PrepStation`
     * before `mount()` runs. Typing the param as `PrepStation` (not `string`)
     * lets the binding line up; an unknown value gets rejected upstream by
     * the route's `whereIn` constraint, never reaching this method.
     */
    public function mount(PrepStation $station): void
    {
        $this->station = $station;
    }

    /**
     * Advance one line through `pending → preparing → ready → completed`.
     * Idempotent at the terminal state — the kitchen can hammer it.
     */
    public function advance(int $lineId): void
    {
        $line = PosOrderLine::query()->find($lineId);

        if ($line === null) {
            return;
        }

        if (! $this->lineBelongsToStation($line)) {
            return; // station-cross safety: a Shisha screen can't move a Kitchen line
        }

        $line->advancePrep();
    }

    /**
     * Mark every active line on this order (for this station) as Ready.
     * Bulk "all done" button on the card header — saves the cook from
     * tapping each row individually when a multi-item ticket finishes
     * together. Skips lines already past Ready.
     */
    public function markOrderReady(int $orderId): void
    {
        $lines = $this->stationLinesForOrder($orderId)
            ->filter(static fn (PosOrderLine $l) => in_array($l->prep_status, [PrepStatus::Pending, PrepStatus::Preparing], true));

        foreach ($lines as $line) {
            // Walk forward to Ready regardless of starting state.
            while ($line->prep_status !== PrepStatus::Ready) {
                $before = $line->prep_status;
                $line->advancePrep();
                if ($line->prep_status === $before) {
                    break; // guard against infinite loop on a degenerate state
                }
            }
        }
    }

    /**
     * Dismiss the whole ticket — mark every Ready / Preparing / Pending
     * line on this order (for this station) as Completed. Used by the
     * "Complete" button on a Ready ticket once the runner picks it up.
     */
    public function completeOrder(int $orderId): void
    {
        $lines = $this->stationLinesForOrder($orderId);

        foreach ($lines as $line) {
            if ($line->prep_status === PrepStatus::Completed || $line->prep_status === null) {
                continue;
            }
            $line->prep_status = PrepStatus::Completed;
            $line->prep_completed_at = now();
            $line->save();
        }
    }

    public function render(): View
    {
        $tickets = $this->loadTickets();

        // Diagnostic: how many categories are actually wired to this
        // station? An empty KDS is almost always "no categories assigned"
        // (the listener correctly routes nothing). Surface the count and
        // the names so the empty state can tell the admin exactly what
        // to do instead of just showing "No tickets."
        $routedCategories = \Modules\Pos\Models\PosCategory::query()
            ->where('station', $this->station->value)
            ->orderBy('sequence')
            ->orderBy('name')
            ->get();

        return view('pos::kitchen-display', [
            'tickets' => $tickets,
            'columns' => [
                PrepStatus::Pending,
                PrepStatus::Preparing,
                PrepStatus::Ready,
            ],
            // Sorted active-ticket ids — the JS audio hook diffs this
            // list against the previous render to detect new arrivals.
            'activeTicketIds' => $tickets
                ->map(static fn (KitchenTicket $t): int => $t->orderId)
                ->sort()
                ->values()
                ->all(),
            'routedCategories' => $routedCategories,
        ]);
    }

    /**
     * Fetch every still-on-screen line for this station and group them
     * by order. One "ticket" = one order's lines for this station. A
     * single bill with both food + shisha produces two tickets on two
     * different screens.
     *
     * @return Collection<int, KitchenTicket>
     */
    private function loadTickets(): Collection
    {
        $lines = PosOrderLine::query()
            ->with([
                'order:id,reference,ordered_at',
                'product:id,pos_category_id',
            ])
            ->whereIn('prep_status', array_map(static fn (PrepStatus $s): string => $s->value, PrepStatus::active()))
            ->whereHas('product.category', function ($q): void {
                $q->where('station', $this->station->value);
            })
            ->orderBy('prep_sent_at')
            ->get();

        // Status priority for the ticket-level rollup: Pending < Preparing
        // < Ready, so the ticket sits in the LEAST-progressed line's
        // column. A multi-item ticket only graduates to Ready when every
        // line is ready.
        $statusOrder = array_flip(array_map(static fn (PrepStatus $s): string => $s->value, PrepStatus::active()));

        /** @var Collection<int, KitchenTicket> $tickets */
        $tickets = $lines
            ->groupBy('pos_order_id')
            ->map(static function (Collection $orderLines) use ($statusOrder): KitchenTicket {
                /** @var PosOrderLine $first */
                $first = $orderLines->first();
                $orderRef = $first->order !== null ? $first->order->reference : '';
                $sentAt = $first->prep_sent_at ?? \Illuminate\Support\Carbon::now();

                $earliestStatus = PrepStatus::Pending;
                $earliestRank = PHP_INT_MAX;
                foreach ($orderLines as $line) {
                    $s = $line->prep_status;
                    if ($s === null) {
                        continue;
                    }
                    $rank = $statusOrder[$s->value] ?? PHP_INT_MAX;
                    if ($rank < $earliestRank) {
                        $earliestRank = $rank;
                        $earliestStatus = $s;
                    }
                }

                return new KitchenTicket(
                    orderId: (int) $first->pos_order_id,
                    reference: $orderRef,
                    sentAt: $sentAt,
                    status: $earliestStatus,
                    lines: $orderLines->values(),
                );
            })
            ->values();

        return $tickets->sortBy(static fn (KitchenTicket $t): int => $t->sentAt->getTimestamp())->values();
    }

    /**
     * Lines on this order that route to this screen's station.
     *
     * @return Collection<int, PosOrderLine>
     */
    private function stationLinesForOrder(int $orderId): Collection
    {
        return PosOrderLine::query()
            ->with(['product:id,pos_category_id', 'product.category:id,station'])
            ->where('pos_order_id', $orderId)
            ->get()
            ->filter(fn (PosOrderLine $line): bool => $this->lineBelongsToStation($line))
            ->values();
    }

    private function lineBelongsToStation(PosOrderLine $line): bool
    {
        $product = $line->product;
        $category = $product?->category;
        return $category?->station === $this->station;
    }
}

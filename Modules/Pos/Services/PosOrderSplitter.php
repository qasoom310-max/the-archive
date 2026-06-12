<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Exceptions\PosOrderSplitException;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderLine;
use Modules\Pos\Models\PosSession;

/**
 * Splits a POS order: a chosen quantity of selected lines is moved off a
 * source order onto a new (or merged-into) order — the Odoo / SierraPOS
 * "split the bill" gesture. The single domain entry point; the Livewire
 * modal is a thin shell over {@see split()}.
 *
 * Two paths, both atomic (the whole split runs inside one session-locked
 * transaction, so a validation throw leaves nothing half-moved):
 *
 *  - **Draft source** — the everyday dine-in case. Moved lines land on the
 *    destination table's open draft (merged if one exists, so the
 *    one-draft-per-table invariant holds), else a fresh draft is created.
 *    No money is involved.
 *
 *  - **Paid (Done) source** — a finalised sale is re-organised into two
 *    completed orders. Crucially this NEVER re-fires {@see PosOrderPaid}:
 *    stock is already consumed (the new order is flagged
 *    `components_consumed`), and no second journal entry / KDS ticket /
 *    WhatsApp receipt is produced. The combined revenue, tax and stock
 *    across the two orders are identical to the original — only the
 *    payment records and the per-phone discount are re-apportioned so each
 *    order balances on its own.
 */
final class PosOrderSplitter
{
    /**
     * @param  array<int|string, int|string>  $quantities  line id => units to move
     *
     * @throws PosOrderSplitException
     */
    public function split(
        PosOrder $source,
        array $quantities,
        ?int $destinationTableId,
        ?string $notes = null,
    ): PosOrder {
        if (! in_array($source->state, [OrderState::Draft, OrderState::Done], true)) {
            throw new PosOrderSplitException(__('Only open or paid orders can be split.'));
        }

        if ($source->state === OrderState::Draft && $source->pos_table_id === $destinationTableId) {
            throw new PosOrderSplitException(__('Choose a different table for the split.'));
        }

        /** @var Collection<int, PosOrderLine> $lines */
        $lines = $source->lines()->get()->keyBy('id');

        $selections = $this->resolveSelections($lines, $quantities);
        $movingUnits = array_sum($selections);
        $totalUnits = (int) $lines->sum(static fn (PosOrderLine $l): int => (int) $l->qty);

        if ($movingUnits < 1) {
            throw new PosOrderSplitException(__('Select at least one item to split.'));
        }

        if ($movingUnits >= $totalUnits) {
            throw new PosOrderSplitException(__('You must leave at least one item on the original order.'));
        }

        return DB::transaction(function () use ($source, $lines, $selections, $movingUnits, $destinationTableId, $notes): PosOrder {
            // Serialise concurrent terminals on this register so two splits
            // (or a split racing a new draft) can't compute the same next
            // reference and collide on `pos_orders_reference_unique`.
            PosSession::query()->whereKey($source->pos_session_id)->lockForUpdate()->first();

            $destination = $this->resolveDestination($source, $destinationTableId, $notes);

            foreach ($selections as $lineId => $moveQty) {
                $line = $lines->get($lineId);
                if ($line !== null) {
                    $this->moveUnits($destination, $line, $moveQty);
                }
            }

            $source->recalculate();
            $destination->recalculate();

            if ($source->state === OrderState::Done) {
                $this->reallocatePayments($source, $destination);
            }

            $source->logChange(__('Split — moved :count item(s) to :ref.', [
                'count' => $movingUnits,
                'ref' => $destination->reference,
            ]));
            $destination->logChange(__('Created by splitting :ref.', ['ref' => $source->reference]));

            return $destination->refresh();
        });
    }

    /**
     * Clamp the raw modal selection to valid, in-range whole units that
     * actually belong to the source order.
     *
     * @param  Collection<int, PosOrderLine>  $lines
     * @param  array<int|string, int|string>  $quantities
     * @return array<int, int> line id => units to move (>= 1)
     */
    private function resolveSelections(Collection $lines, array $quantities): array
    {
        $selections = [];

        foreach ($quantities as $lineId => $qty) {
            $line = $lines->get((int) $lineId);
            if ($line === null) {
                continue;
            }

            $move = min((int) $qty, (int) $line->qty);
            if ($move < 1) {
                continue;
            }

            $selections[(int) $line->id] = $move;
        }

        return $selections;
    }

    /**
     * Where the moved lines land. A paid source always spawns a fresh Done
     * order; a draft source merges into the destination table's open draft
     * (preserving one-draft-per-table) or opens a new one.
     */
    private function resolveDestination(PosOrder $source, ?int $tableId, ?string $notes): PosOrder
    {
        if ($source->state === OrderState::Done) {
            return $this->createOrder($source, $tableId, $notes, OrderState::Done, $source->customer_discount_percent);
        }

        $existing = PosOrder::query()
            ->where('pos_session_id', $source->pos_session_id)
            ->where('state', OrderState::Draft)
            ->where('id', '!=', $source->id)
            ->when(
                $tableId === null,
                static fn ($q) => $q->whereNull('pos_table_id'),
                static fn ($q) => $q->where('pos_table_id', $tableId),
            )
            ->latest('id')
            ->first();

        return $existing ?? $this->createOrder($source, $tableId, $notes, OrderState::Draft, 0.0);
    }

    private function createOrder(
        PosOrder $source,
        ?int $tableId,
        ?string $notes,
        OrderState $state,
        float $discountPercent,
    ): PosOrder {
        $isDone = $state === OrderState::Done;
        $note = $notes !== null ? trim($notes) : '';

        return PosOrder::query()->create([
            'pos_session_id' => $source->pos_session_id,
            'pos_table_id' => $tableId,
            'user_id' => $source->user_id,
            'partner_id' => $source->partner_id,
            'reference' => $this->nextReference($source->pos_session_id),
            'state' => $state,
            'customer_discount_percent' => round($discountPercent, 2),
            // A paid split inherits the already-consumed flag so the new
            // order never decrements stock a second time.
            'components_consumed' => $isDone,
            'customer_phone' => $isDone ? $source->customer_phone : null,
            'ordered_at' => $isDone ? $source->ordered_at : null,
            'notes' => $note !== '' ? $note : null,
        ]);
    }

    /**
     * Next session-scoped reference (POS/{session}/{seq}), seq = max+1.
     * Mirrors PosTerminal::resolveDraftOrder — parses the trailing digits
     * in PHP so it stays portable across SQLite / MySQL / Postgres.
     */
    private function nextReference(int $sessionId): string
    {
        $maxSeq = (int) PosOrder::query()
            ->where('pos_session_id', $sessionId)
            ->pluck('reference')
            ->map(static fn (string $r): int => (int) substr($r, (int) strrpos($r, '/') + 1))
            ->max();

        return sprintf('POS/%d/%04d', $sessionId, $maxSeq + 1);
    }

    /**
     * Move `$moveQty` units of `$line` onto `$destination` as a fresh line
     * (a snapshot copy — product, price, discount, tax, condiments and the
     * KDS prep state all carry over). A partial move shrinks the source
     * line; a whole move deletes it.
     */
    private function moveUnits(PosOrder $destination, PosOrderLine $line, int $moveQty): void
    {
        $copy = $destination->lines()->make([
            'pos_product_id' => $line->pos_product_id,
            'name' => $line->name,
            'notes' => $line->notes,
            'condiments' => $line->condiments,
            'unit_price' => $line->unit_price,
            'discount' => $line->discount,
            'tax_rate' => $line->tax_rate,
            'prep_status' => $line->prep_status,
            'prep_sent_at' => $line->prep_sent_at,
            'prep_started_at' => $line->prep_started_at,
            'prep_ready_at' => $line->prep_ready_at,
            'prep_completed_at' => $line->prep_completed_at,
        ]);
        $copy->qty = (float) $moveQty;
        $copy->recompute();
        $copy->save();

        if ($moveQty >= (int) $line->qty) {
            $line->delete();

            return;
        }

        $line->qty -= $moveQty;
        $line->recompute();
        $line->save();
    }

    /**
     * Move exactly `$destination->total` worth of payment off the source's
     * payment records onto the new order (greedy, exact — splitting a
     * single payment record when needed). Method breakdown is preserved.
     * Any cash overpayment / change stays with the source. Combined
     * tendered cash across both orders is unchanged.
     */
    private function reallocatePayments(PosOrder $source, PosOrder $destination): void
    {
        $remaining = round($destination->total, 2);

        if ($remaining > 0.0001) {
            foreach ($source->payments()->orderBy('id')->get() as $payment) {
                if ($remaining <= 0.0001) {
                    break;
                }

                $take = round(min((float) $payment->amount, $remaining), 2);
                if ($take <= 0) {
                    continue;
                }

                $destination->payments()->create([
                    'pos_payment_method_id' => $payment->pos_payment_method_id,
                    'amount' => $take,
                    'paid_at' => $payment->paid_at ?? Carbon::now(),
                ]);

                $left = round((float) $payment->amount - $take, 2);
                if ($left <= 0.0001) {
                    $payment->delete();
                } else {
                    $payment->amount = $left;
                    $payment->save();
                }

                $remaining = round($remaining - $take, 2);
            }
        }

        $this->refreshPaymentTotals($destination);
        $this->refreshPaymentTotals($source);
    }

    private function refreshPaymentTotals(PosOrder $order): void
    {
        $paid = $order->paymentsTotal();
        $order->paid_total = $paid;
        $order->change_due = round(max(0.0, $paid - $order->total), 2);
        $order->save();
    }
}

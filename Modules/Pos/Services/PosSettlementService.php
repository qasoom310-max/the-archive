<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SalesChannel;
use Modules\Pos\Enums\SettlementState;
use Modules\Pos\Events\PosSettlementReceived;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosSettlement;

/**
 * Tracks delivery money from "collected by someone else" to "in our account".
 *
 * A remote sale's cash never reaches us directly — the delivery company collects
 * it (or the customer bank-transfers it). This service holds those orders in a
 * pending pool, turns a batch of them into a payout request with the amount we
 * expect, and then reconciles what actually arrived against it.
 *
 * The company deducts its delivery fee before remitting, so the expected amount
 * is the collected total MINUS those fees — a "short" transfer of exactly the
 * fees is normal and must not read as a discrepancy.
 */
final class PosSettlementService
{
    /**
     * Delivery orders whose money is collected but not yet in our account.
     *
     * Paid, delivered-channel orders that no payout covers yet. An unpaid COD
     * order is deliberately excluded: the customer hasn't paid, so nobody is
     * holding our money.
     *
     * @return Builder<PosOrder>
     */
    public function pendingQuery(): Builder
    {
        return PosOrder::query()
            ->where('channel', SalesChannel::Remote->value)
            ->where('state', OrderState::Done->value)
            ->whereNull('pos_settlement_id')
            // Fully paid — the money exists and someone else is holding it.
            ->whereRaw('paid_total >= total - 0.001')
            ->where('total', '>', 0);
    }

    /**
     * What the pending pool is worth right now.
     *
     * @return array{orders: int, collected: float, fees: float, expected: float}
     */
    public function pendingSummary(): array
    {
        $collected = round((float) $this->pendingQuery()->sum('total'), 3);
        $fees = round((float) $this->pendingQuery()->sum('delivery_fee'), 3);

        return [
            'orders' => $this->pendingQuery()->count(),
            'collected' => $collected,
            'fees' => $fees,
            'expected' => round($collected - $fees, 3),
        ];
    }

    /**
     * Request a payout for everything currently pending: snapshot the orders and
     * the amount we expect, and attach them so the batch can't drift.
     *
     * Returns null when there is nothing pending.
     */
    public function requestPayout(?string $note = null): ?PosSettlement
    {
        return DB::transaction(function () use ($note): ?PosSettlement {
            $orders = $this->pendingQuery()->lockForUpdate()->get();

            if ($orders->isEmpty()) {
                return null;
            }

            $collected = round((float) $orders->sum('total'), 3);
            $fees = round((float) $orders->sum('delivery_fee'), 3);

            $settlement = PosSettlement::query()->create([
                'reference' => $this->nextReference(),
                'state' => SettlementState::Requested->value,
                'requested_at' => Carbon::now(),
                'collected_total' => $collected,
                'fees_deducted' => $fees,
                // They keep their delivery fee, so this is what should arrive.
                'expected_amount' => round($collected - $fees, 3),
                'user_id' => Auth::id(),
                'note' => $note,
            ]);

            PosOrder::query()
                ->whereIn('id', $orders->pluck('id'))
                ->update(['pos_settlement_id' => $settlement->id]);

            return $settlement;
        });
    }

    /**
     * Record the money that actually arrived and reconcile it against what we
     * asked for. A gap is stored rather than hidden, so a short or wrong
     * transfer surfaces on the settlements screen.
     */
    public function recordReceipt(
        PosSettlement $settlement,
        float $amount,
        ?string $method = null,
        ?string $receivedOn = null,
        ?string $note = null,
    ): PosSettlement {
        if ($settlement->isReceived()) {
            return $settlement;
        }

        $received = round(max(0.0, $amount), 3);

        $settlement->received_amount = $received;
        $settlement->difference = round($received - (float) $settlement->expected_amount, 3);
        $settlement->received_at = $receivedOn !== null && $receivedOn !== ''
            ? Carbon::parse($receivedOn)
            : Carbon::now();
        $settlement->method = $method;
        $settlement->state = SettlementState::Received;

        if ($note !== null && $note !== '') {
            $settlement->note = $note;
        }

        $settlement->save();

        // Books: move the money out of "in transit" and into the bank.
        PosSettlementReceived::dispatch($settlement);

        return $settlement;
    }

    /**
     * Undo a payout request that was never actually sent — releases its orders
     * back into the pending pool. Only while still `requested`.
     */
    public function cancel(PosSettlement $settlement): void
    {
        if ($settlement->isReceived()) {
            return;
        }

        DB::transaction(function () use ($settlement): void {
            $settlement->orders()->update(['pos_settlement_id' => null]);
            $settlement->delete();
        });
    }

    private function nextReference(): string
    {
        $last = (int) PosSettlement::query()->max('id');

        return sprintf('SET/%04d', $last + 1);
    }
}

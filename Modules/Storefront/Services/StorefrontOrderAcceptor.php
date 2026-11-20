<?php

declare(strict_types=1);

namespace Modules\Storefront\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Enums\SalesChannel;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Services\PosSessionManager;
use Modules\Storefront\Models\StorefrontOrder;
use RuntimeException;

/**
 * Turns an accepted website order into an ordinary remote POS order, paid on
 * delivery — exactly what a cashier makes with "New remote order" and
 * "Confirm, pay on delivery". From then on it is the register's: it shows on
 * the Remote sales dashboard as New, stock is used as for any sale, and the
 * money is booked when the driver collects it.
 *
 * Prices are the ones filed when the order arrived (what the customer was
 * shown and agreed to), not today's.
 */
final class StorefrontOrderAcceptor
{
    public function __construct(private readonly PosSessionManager $sessions)
    {
    }

    /** @throws RuntimeException when no register is open or the order was already handled */
    public function accept(StorefrontOrder $inbox, ?int $userId): PosOrder
    {
        $session = $this->sessions->getActiveSession();
        if ($session === null) {
            throw new RuntimeException(__('Open the register first: an accepted order goes into the open POS session.'));
        }

        return DB::transaction(function () use ($inbox, $userId, $session): PosOrder {
            $fresh = StorefrontOrder::query()->whereKey($inbox->id)->lockForUpdate()->first();
            if ($fresh === null || ! $fresh->isNew()) {
                throw new RuntimeException(__('This website order has already been dealt with.'));
            }

            $order = PosOrder::openDraft((int) $session->id, [
                'user_id' => $userId,
                'channel' => SalesChannel::Remote,
                'customer_name' => $fresh->customer_name,
                'customer_phone' => $fresh->customer_phone,
                'delivery_address' => $fresh->delivery_address,
                'delivery_charge' => $fresh->delivery_charge,
                'notes' => trim(__('Website order :ref', ['ref' => $fresh->source_reference]) . "\n" . (string) $fresh->notes),
            ]);

            foreach ($fresh->lines as $line) {
                $posLine = $order->lines()->make([
                    'pos_product_id' => $line['product_id'],
                    'name' => $line['name'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'discount' => 0,
                    'tax_rate' => $line['tax_rate'] ?? 0,
                ]);
                $posLine->recompute();
                $posLine->save();
            }

            $order->recalculate();
            $order->confirmUnpaid();

            $fresh->forceFill([
                'status' => StorefrontOrder::STATUS_ACCEPTED,
                'pos_order_id' => $order->id,
                'handled_by' => $userId,
                'handled_at' => Carbon::now(),
            ])->save();

            return $order;
        });
    }

    public function reject(StorefrontOrder $inbox, ?int $userId, string $reason): void
    {
        DB::transaction(function () use ($inbox, $userId, $reason): void {
            $fresh = StorefrontOrder::query()->whereKey($inbox->id)->lockForUpdate()->first();
            if ($fresh === null || ! $fresh->isNew()) {
                throw new RuntimeException(__('This website order has already been dealt with.'));
            }

            $fresh->forceFill([
                'status' => StorefrontOrder::STATUS_REJECTED,
                'handled_by' => $userId,
                'handled_at' => Carbon::now(),
                'reject_reason' => mb_substr(trim($reason), 0, 255) ?: null,
            ])->save();
        });
    }
}

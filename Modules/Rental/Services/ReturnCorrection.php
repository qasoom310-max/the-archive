<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Money\Currencies;
use Illuminate\Support\Facades\DB;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalOrder;

/**
 * Puts right what was typed wrongly when a car came back.
 *
 * Returning the car closes the order, and a closed order's return figures
 * could not be changed by anyone. So one slip at the desk (the odometer typed
 * into the fuel-charge box) left a customer owing 121,923 BD for petrol, with
 * no way to fix it short of editing the database.
 *
 * A correction rewrites the return's KM, fuel level, fuel charge and extra
 * charge, and then carries the new figures everywhere the old ones had gone:
 *  - the order is re-priced (total, balance, payment status);
 *  - an invoice already raised from the order takes the new total and its
 *    paid / part-paid / unpaid state is worked out again;
 *  - the car's odometer follows a corrected KM, unless the car has moved on
 *    since (then a later reading is the truth and is left alone).
 *
 * It never touches money actually received, receipts, or a deposit that was
 * already settled. Every correction is written to the activity log with the
 * old figure, the new one, who made it and why.
 */
final class ReturnCorrection
{
    /**
     * @param array{return_km: ?int, return_fuel: string, fuel_charge: float, extra_charge: float, extra_charge_note: ?string} $new
     *
     * @return list<string> what changed, one line each ("Fuel charge: 121,922.00 → 12.00"); empty when nothing did
     */
    public function apply(RentalOrder $order, array $new, string $reason): array
    {
        if ($order->state !== RentalOrder::STATE_CLOSED || $order->returned_at === null) {
            return [];
        }

        $money = static fn (float $v): string => Currencies::format($v);

        $changes = [];
        if ($order->return_km !== $new['return_km']) {
            $changes[] = __('Return KM') . ': ' . ($order->return_km ?? '—') . ' → ' . ($new['return_km'] ?? '—');
        }
        if ((string) $order->return_fuel !== $new['return_fuel']) {
            $changes[] = __('Return fuel') . ': ' . RentalOrder::fuelLabel($order->return_fuel) . ' → ' . RentalOrder::fuelLabel($new['return_fuel']);
        }
        if (abs($order->fuel_charge - $new['fuel_charge']) > 0.0005) {
            $changes[] = __('Fuel charge') . ': ' . $money($order->fuel_charge) . ' → ' . $money($new['fuel_charge']);
        }
        if (abs($order->extra_charge - $new['extra_charge']) > 0.0005) {
            $changes[] = __('Extra charge') . ': ' . $money($order->extra_charge) . ' → ' . $money($new['extra_charge']);
        }
        if ((string) $order->extra_charge_note !== (string) $new['extra_charge_note']) {
            $changes[] = __('Extra charge note') . ': ' . ($order->extra_charge_note ?? '—') . ' → ' . ($new['extra_charge_note'] ?? '—');
        }

        if ($changes === []) {
            return [];
        }

        DB::transaction(function () use ($order, $new, $money, &$changes): void {
            $oldKm = $order->return_km;
            $oldTotal = $order->total;
            $oldLines = $order->invoiceChargeLines();

            $order->return_km = $new['return_km'];
            $order->return_fuel = $new['return_fuel'];
            $order->fuel_charge = $new['fuel_charge'];
            $order->extra_charge = $new['extra_charge'];
            $order->extra_charge_note = $new['extra_charge_note'];
            $order->recalcTotals();
            $order->save();

            if (abs($oldTotal - $order->total) > 0.0005) {
                $changes[] = __('Total') . ': ' . $money($oldTotal) . ' → ' . $money($order->total);
            }

            // The odometer was set from the return reading. It follows the
            // correction only while it still holds that reading: a car that
            // has been out again since has a newer, truer one.
            $car = $order->vehicle;
            if ($car !== null && $oldKm !== null && $new['return_km'] !== null && $car->odometer === $oldKm && $oldKm !== $new['return_km']) {
                $car->update(['odometer' => $new['return_km']]);
            }

            $invoice = RentalInvoice::query()->where('order_id', $order->id)->first();
            if ($invoice !== null) {
                $invoice->subtotal = $order->subtotal;
                $invoice->discount = $order->discount;
                $invoice->total = $order->total;

                // The invoice's note quoted the old charges. Swap just those
                // lines, so anything the office wrote beside them is kept.
                $notes = (string) $invoice->notes;
                $old = trim(implode("\n", $oldLines));
                $fresh = trim(implode("\n", $order->invoiceChargeLines()));
                if ($old !== '' && str_contains($notes, $old)) {
                    $notes = trim(str_replace($old, $fresh, $notes));
                } elseif ($old === '' && $fresh !== '') {
                    $notes = trim($notes . "\n" . $fresh);
                }
                $invoice->notes = $notes !== '' ? $notes : null;
                $invoice->save();
                // Paid / part-paid / unpaid against the corrected total.
                $invoice->recomputePaid();

                $changes[] = __('Invoice :ref updated', ['ref' => (string) $invoice->reference]);
            }
        });

        app(ActivityLogger::class)->logFor(
            $order,
            'return_corrected',
            implode(' · ', $changes) . ' — ' . __('Reason') . ': ' . $reason,
        );

        return $changes;
    }
}

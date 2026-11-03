<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * VAT no longer applies to the drop-off / pick-up charge (RentalOrder::recalcTotals).
 * Re-figure the orders still to be settled that were priced under the old rule:
 * draft or active, nothing received, no invoice raised, and a VAT that equals
 * the old formula exactly (so imported figures and hand corrections are left
 * alone). Only VAT, total and balance move; everything else stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_orders') || ! Schema::hasColumn('rental_orders', 'extra_charge')) {
            return;
        }

        $invoiced = Schema::hasTable('rental_invoices')
            ? DB::table('rental_invoices')->whereNotNull('order_id')->pluck('order_id')->map(fn ($id): int => (int) $id)->all()
            : [];

        $changed = [];
        DB::table('rental_orders')
            ->whereIn('state', ['draft', 'active'])
            ->where('delivery_charges', '>', 0)
            ->where(fn ($q) => $q->where('advance_amount', 0)->orWhereNull('advance_amount'))
            ->orderBy('id')
            ->get(['id', 'reference', 'subtotal', 'discount', 'extra_charge', 'delivery_charges', 'vat_rate', 'vat_amount', 'total'])
            ->each(function (object $o) use ($invoiced, &$changed): void {
                if (in_array((int) $o->id, $invoiced, true)) {
                    return;
                }
                $rate = (float) $o->vat_rate / 100;
                $rental = max(0.0, (float) $o->subtotal - (float) $o->discount) + max(0.0, (float) $o->extra_charge);
                $oldVat = round(($rental + (float) $o->delivery_charges) * $rate, 3);
                if (abs($oldVat - (float) $o->vat_amount) > 0.0005) {
                    return;
                }
                $newVat = round($rental * $rate, 3);
                $total = round((float) $o->total - $oldVat + $newVat, 3);
                DB::table('rental_orders')->where('id', $o->id)->update([
                    'vat_amount' => $newVat,
                    'total' => $total,
                    'balance' => max(0.0, $total),
                ]);
                $changed[] = $o->reference . ' (VAT ' . $oldVat . ' → ' . $newVat . ', total ' . $o->total . ' → ' . $total . ')';
            });

        if ($changed !== []) {
            Log::info('Rental: delivery no longer taxed on ' . implode('; ', $changed));
            app(ActivityLogger::class)->log('updated', 'Rental orders', 'Delivery charge no longer taxed: ' . implode('; ', $changed));
        }
    }

    public function down(): void
    {
    }
};

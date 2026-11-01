<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Rental\Models\RentalOrder;

/**
 * Orders brought in by the Orders-list import kept a rate of 0 (the importer
 * stored the old system's figures but no rate), so the first save or car
 * return re-priced them from 0 × days to a total of 0, marked Paid. Give each
 * one still holding its figures (rate 0, subtotal > 0) the rate those figures
 * imply — nothing it shows changes; only a later re-price now keeps the money.
 * Orders already re-priced to 0 can't be recovered from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_orders') || ! Schema::hasColumn('rental_orders', 'rate')) {
            return;
        }

        $fixed = [];
        RentalOrder::query()->where('rate', 0)->where('subtotal', '>', 0)
            ->each(static function (RentalOrder $order) use (&$fixed): void {
                $units = $order->billableUnits();
                if ($units <= 0) {
                    return;
                }
                $order->forceFill(['rate' => round((float) $order->subtotal / $units, 3)])->saveQuietly();
                $fixed[] = (string) $order->reference;
            });

        if ($fixed !== []) {
            Log::info('Rental: rate set on imported orders ' . implode(', ', $fixed));
        }
    }

    public function down(): void
    {
    }
};

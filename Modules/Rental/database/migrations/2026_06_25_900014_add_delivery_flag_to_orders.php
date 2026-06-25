<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery is now a simple on/off choice rather than a free-typed charge: when
 * ticked, a flat delivery fee (3 BHD) is added to the order automatically and
 * can't be edited. `delivery_charges` stays as the stored amount (0 or the flat
 * fee) so reports keep reading it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->boolean('delivery')->default(false)->after('delivery_charges');
        });

        // Backfill the flag for any existing order that already had a charge.
        \Illuminate\Support\Facades\DB::table('rental_orders')
            ->where('delivery_charges', '>', 0)
            ->update(['delivery' => true]);
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn('delivery');
        });
    }
};

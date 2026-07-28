<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery charged TO the customer, distinct from `delivery_fee` (the cost WE
 * absorb). A shop eats the normal delivery, but on an urgent request — or an
 * offer that doesn't include free delivery — the customer pays it: that amount
 * goes on the bill and counts as revenue, while `delivery_fee` stays our
 * expense. Both live on the same order because a single delivery can be split
 * (we pay the base, the customer pays the urgent extra).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->decimal('delivery_charge', 10, 3)->default(0)->after('delivery_fee');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn('delivery_charge');
        });
    }
};

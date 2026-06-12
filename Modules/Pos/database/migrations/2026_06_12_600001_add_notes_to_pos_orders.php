<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order-level free-text note. Set when an order is created by splitting
 * another (the "Notes (Optional)" field on the split modal travels onto
 * the new order), and surfaced on the Orders list / terminal. Nullable —
 * the vast majority of orders carry none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};

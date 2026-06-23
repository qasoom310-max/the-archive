<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item minimum stock for ingredients + condiments (products already have
 * `reorder_point`). Nullable — blank means "use the global low-stock
 * threshold", so existing rows behave exactly as before. The Stock Report and
 * the Purchase Reorder Report flag an item as low at or below this level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->decimal('reorder_point', 12, 3)->nullable()->after('stock_on_hand');
        });

        Schema::table('pos_condiments', function (Blueprint $table): void {
            $table->decimal('reorder_point', 12, 3)->nullable()->after('stock_on_hand');
        });
    }

    public function down(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->dropColumn('reorder_point');
        });

        Schema::table('pos_condiments', function (Blueprint $table): void {
            $table->dropColumn('reorder_point');
        });
    }
};

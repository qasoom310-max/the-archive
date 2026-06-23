<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stock move can now reference a POS **ingredient** (raw material) instead of
 * a product. `item_type` disambiguates what `product_id` points at:
 * 'product' (default — every existing row) or 'ingredient'.
 *
 * Ingredient moves are recorded as a Done audit trail for the Inventory
 * dashboard ONLY — they are never process()ed, so they never touch the
 * product-keyed `stock_quants` ledger (ingredient on-hand stays authoritative
 * in `pos_ingredients`). The column is purely so the two are distinguishable
 * and a future quant rebuild can skip ingredient rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_moves', function (Blueprint $table): void {
            $table->string('item_type')->default('product')->after('product_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('stock_moves', function (Blueprint $table): void {
            $table->dropColumn('item_type');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production & store support on a product (perfumes POS): `bottle_size_ml` is the
 * fill size of a finished bottle, and `store_stock` is back-store stock produced
 * but not yet moved to the shop. `stock_on_hand` stays the shop/display stock the
 * register sells from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->decimal('bottle_size_ml', 12, 3)->nullable()->after('unit');
            $table->decimal('store_stock', 12, 3)->default(0)->after('stock_on_hand');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn(['bottle_size_ml', 'store_stock']);
        });
    }
};

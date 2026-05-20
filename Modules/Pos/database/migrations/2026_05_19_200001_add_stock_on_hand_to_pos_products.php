<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory level for POS products. Components (raw materials) are also
 * POS products, so a single stock column serves both finished goods and
 * ingredients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->decimal('stock_on_hand', 12, 3)->default(0)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('stock_on_hand');
        });
    }
};

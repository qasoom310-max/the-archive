<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the cost-side of the sale-cost-profit triangle. `pos_products.price`
 * stays as the sale price (labelled "Sale Price" in the UI/imports); the
 * new column holds the procurement cost. `profit` is computed in PHP via
 * an accessor — kept as a derived value to avoid a stored column that
 * would drift if either input changed without a recompute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            // Nullable so existing rows don't need a backfill; default 0 so
            // `profit` is well-defined the moment a product is created.
            $table->decimal('cost_price', 12, 2)->default(0)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('cost_price');
        });
    }
};

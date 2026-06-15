<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unit of measure for a product's stock — `qty` (each), `kg`, `g`, `l`,
 * `ml`, `pcs`, `box`, `pack`, `dozen`. Shown next to "Stock on hand" on the
 * product form so staff can stock by weight/volume, not just count. Plain
 * string (NOT an enum cast — sidesteps the engine FormView empty-option
 * pitfall); defaults to `qty`, which also backfills every existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->string('unit', 16)->default('qty')->after('stock_on_hand');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('unit');
        });
    }
};

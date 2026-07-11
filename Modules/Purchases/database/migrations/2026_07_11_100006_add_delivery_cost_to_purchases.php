<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A purchase can carry a delivery / shipping cost that isn't on the vendor's
 * invoice (a hidden cost we pay separately). On confirm it's distributed across
 * the lines by value and folded into each item's landed cost — so a material's
 * cost price reflects what it truly cost to get it here, not just the invoice
 * unit price. `landed_unit_cost` records that per-line landed figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->decimal('delivery_cost', 12, 2)->default(0)->after('total');
        });

        Schema::table('purchase_lines', function (Blueprint $table): void {
            // Unit cost + this line's share of delivery, set at confirm time.
            $table->decimal('landed_unit_cost', 12, 4)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn('delivery_cost');
        });
        Schema::table('purchase_lines', function (Blueprint $table): void {
            $table->dropColumn('landed_unit_cost');
        });
    }
};

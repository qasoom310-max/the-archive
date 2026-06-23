<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bill line can now buy a condiment as well as a product. `pos_condiment_id`
 * is a logical ref to `pos_condiments` (no FK — Purchases stays decoupled from
 * the POS catalogue, like `pos_product_id`). A line carries EITHER a product or
 * a condiment; confirming raises the matching stock_on_hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('pos_condiment_id')->nullable()->after('pos_product_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table): void {
            $table->dropColumn('pos_condiment_id');
        });
    }
};

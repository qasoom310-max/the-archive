<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bill line can now buy an INGREDIENT (raw material) as well as a product or
 * a condiment. `pos_ingredient_id` is a logical ref to `pos_ingredients` (no FK
 * — Purchases stays decoupled from the POS catalogue, like the other refs). A
 * line carries exactly one of product / condiment / ingredient; confirming
 * raises the matching stock_on_hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('pos_ingredient_id')->nullable()->after('pos_condiment_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table): void {
            $table->dropColumn('pos_ingredient_id');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferred supplier (vendor) for a catalogue item — a logical ref to
 * `partners` (no FK, like the other cross-module references). Nullable: blank
 * means "no preferred vendor", in which case the Reorder Report falls back to
 * the last vendor the item was actually bought from. Added to all three
 * purchasable catalogues: products, ingredients and condiments.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pos_products', 'pos_ingredients', 'pos_condiments'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedBigInteger('supplier_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['pos_products', 'pos_ingredients', 'pos_condiments'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('supplier_id');
            });
        }
    }
};

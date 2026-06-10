<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope a condiment to a product category so the register only offers the
 * add-ons that make sense for the product being rung up. Nullable +
 * indexed logical ref (POS keeps no hard FKs across catalogues); null
 * means "global" — the condiment shows for every product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_condiments', function (Blueprint $table): void {
            $table->unsignedBigInteger('pos_category_id')->nullable()->after('price')->index();
        });
    }

    public function down(): void
    {
        Schema::table('pos_condiments', function (Blueprint $table): void {
            $table->dropColumn('pos_category_id');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product condiment assignment: which add-ons the register offers for a
 * given product (on top of any category-scoped / global condiments). A simple
 * many-to-many pivot between pos_products and pos_condiments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_condiment_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('pos_condiment_id')->constrained('pos_condiments')->cascadeOnDelete();
            // Short explicit name — the auto-generated one would exceed MySQL's
            // 64-char identifier cap. [[mysql-index-name-64-char-cap]]
            $table->unique(['pos_product_id', 'pos_condiment_id'], 'pos_cond_prod_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_condiment_product');
    }
};

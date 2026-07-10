<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The standard production formula for a product (perfumes): the raw materials
 * and ML per batch (e.g. 500 ml oil + 1300 ml ethanol). A new production run
 * auto-fills its lines from this, still editable, so mixing is fast and
 * off-recipe batches are obvious.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_product_formula_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('pos_ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->decimal('ml', 14, 3)->default(0);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index('pos_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_formula_lines');
    }
};

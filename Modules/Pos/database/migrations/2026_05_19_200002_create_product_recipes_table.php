<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Static bill of materials: each row says one unit of `parent_product`
 * consumes `quantity_consumed` units of `component_product`. Consumption
 * is strictly static — no per-sale overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_product_id')
                ->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('component_product_id')
                ->constrained('pos_products')->cascadeOnDelete();
            $table->decimal('quantity_consumed', 12, 3);
            $table->timestamps();

            $table->unique(['parent_product_id', 'component_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recipes');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recipe line may now consume a CONDIMENT instead of a product. A line is
 * either a product component (`component_product_id`) OR a condiment component
 * (`component_condiment_id`) — so `component_product_id` becomes nullable.
 * `component_condiment_id` is a logical ref (no FK), consistent with the
 * other cross-concern references in this module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_recipes', function (Blueprint $table): void {
            $table->unsignedBigInteger('component_condiment_id')->nullable()->after('component_product_id');
        });

        // A condiment-only line has no product component.
        Schema::table('product_recipes', function (Blueprint $table): void {
            $table->unsignedBigInteger('component_product_id')->nullable()->change();
        });

        Schema::table('product_recipes', function (Blueprint $table): void {
            $table->index('component_condiment_id');
            // One condiment appears at most once per parent (mirrors the
            // existing product-component unique). NULLs are distinct, so the
            // many existing product rows (condiment id = null) don't collide.
            $table->unique(['parent_product_id', 'component_condiment_id'], 'recipe_parent_condiment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_recipes', function (Blueprint $table): void {
            $table->dropUnique('recipe_parent_condiment_unique');
            $table->dropIndex(['component_condiment_id']);
            $table->dropColumn('component_condiment_id');
        });
    }
};

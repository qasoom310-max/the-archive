<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recipe line may now consume an INGREDIENT (raw material) in addition to a
 * product or a condiment. A line carries exactly one component reference:
 * `component_product_id` OR `component_condiment_id` OR `component_ingredient_id`.
 * `component_ingredient_id` is a logical ref (no FK), consistent with the other
 * cross-concern references in this module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_recipes', function (Blueprint $table): void {
            $table->unsignedBigInteger('component_ingredient_id')->nullable()->after('component_condiment_id');
        });

        Schema::table('product_recipes', function (Blueprint $table): void {
            $table->index('component_ingredient_id');
            // One ingredient appears at most once per parent (mirrors the
            // existing product/condiment-component uniques). NULLs are distinct,
            // so existing product/condiment rows don't collide.
            $table->unique(['parent_product_id', 'component_ingredient_id'], 'recipe_parent_ingredient_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_recipes', function (Blueprint $table): void {
            $table->dropUnique('recipe_parent_ingredient_unique');
            $table->dropIndex(['component_ingredient_id']);
            $table->dropColumn('component_ingredient_id');
        });
    }
};

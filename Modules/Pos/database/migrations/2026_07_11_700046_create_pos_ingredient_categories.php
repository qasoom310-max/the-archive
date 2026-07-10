<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A managed list of ingredient categories (Oils, Bottles, Caps, Pumps, Boxes,
 * Stickers…) so the perfume workshop can group its raw materials and packaging.
 * The admin creates / renames their own; each ingredient may point at one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_ingredient_categories', function (Blueprint $table): void {
            $table->id();
            // Translatable JSON envelope ({"en":..,"ar":..}) — TEXT so both fit.
            $table->text('name');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
        });

        Schema::table('pos_ingredients', function (Blueprint $table): void {
            // Logical ref (no DB FK, matching the rest of the module's soft refs).
            $table->unsignedBigInteger('pos_ingredient_category_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->dropColumn('pos_ingredient_category_id');
        });
        Schema::dropIfExists('pos_ingredient_categories');
    }
};

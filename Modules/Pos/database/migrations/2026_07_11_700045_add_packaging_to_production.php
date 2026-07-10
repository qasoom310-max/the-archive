<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production consumes two kinds of material: liquid (mixed by ML for the whole
 * batch → gives the bottle count) and packaging (bottle, cap, pump, box,
 * sticker… consumed per finished bottle = qty_per_unit × bottles produced). The
 * kind and per-bottle qty are stored on both the run's lines and the product's
 * saved formula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_production_lines', function (Blueprint $table): void {
            $table->string('kind')->default('liquid')->after('pos_ingredient_id'); // liquid | packaging
            $table->decimal('qty_per_unit', 12, 3)->nullable()->after('ml_used'); // packaging: per bottle
        });

        Schema::table('pos_product_formula_lines', function (Blueprint $table): void {
            $table->string('kind')->default('liquid')->after('pos_ingredient_id');
            $table->decimal('qty_per_unit', 12, 3)->nullable()->after('ml');
        });
    }

    public function down(): void
    {
        Schema::table('pos_production_lines', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'qty_per_unit']);
        });
        Schema::table('pos_product_formula_lines', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'qty_per_unit']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw materials / ingredients — stock-tracked recipe components that are never
 * sold or offered at the register (flour, oil, coffee beans…). Mirrors the
 * condiment catalogue but carries a tracked `cost_price` (so it contributes to
 * stock valuation) and a unit of measure, and has no price surcharge / category
 * scoping (it isn't a register add-on).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_ingredients', function (Blueprint $table): void {
            $table->id();
            // Translatable JSON envelope ({"en":..,"ar":..}) — TEXT so both
            // locales fit. Spatie HasTranslations writes/reads the JSON.
            $table->text('name');
            // Procurement cost per unit — drives stock valuation.
            $table->decimal('cost_price', 12, 2)->default(0);
            // On-hand quantity, decremented when a product using this ingredient
            // as a recipe component is sold.
            $table->decimal('stock_on_hand', 12, 3)->default(0);
            // Unit-of-measure code (qty|kg|g|l|ml|…) — same vocabulary as products.
            $table->string('unit')->default('qty');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_ingredients');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturing for the perfumes POS: a production run mixes raw materials
 * (ingredients, in ML) into finished bottles that land in the product's STORE
 * stock; a stock transfer then moves bottles STORE → SHOP so the register can
 * sell them. Both are logged (who / when / session) for accountability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_productions', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('pos_product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->decimal('bottle_size_ml', 12, 3)->default(0);
            $table->decimal('total_mix_ml', 14, 3)->default(0);
            $table->unsignedInteger('expected_units')->default(0);
            $table->unsignedInteger('produced_units')->default(0);
            $table->decimal('total_cost', 14, 3)->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->string('notes')->nullable();
            $table->unsignedBigInteger('produced_by_user_id')->nullable();
            $table->timestamps();

            $table->index('pos_product_id');
        });

        Schema::create('pos_production_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_production_id')->constrained('pos_productions')->cascadeOnDelete();
            $table->foreignId('pos_ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->decimal('ml_used', 14, 3)->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0); // cost per ML at the time
            $table->timestamps();

            $table->index('pos_production_id');
        });

        Schema::create('pos_stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('pos_product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->string('direction')->default('store_to_shop'); // store_to_shop | shop_to_store
            $table->string('notes')->nullable();
            $table->unsignedBigInteger('moved_by_user_id')->nullable();
            $table->timestamps();

            $table->index('pos_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_stock_transfers');
        Schema::dropIfExists('pos_production_lines');
        Schema::dropIfExists('pos_productions');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock quant = the on-hand quantity of a product (optionally a lot) at
 * a single location. The materialised result of all done stock moves;
 * the basis for the Forecasted / Valuation reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_quants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_location_id')->constrained('stock_locations')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('lot_name')->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('reserved_quantity', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['stock_location_id', 'product_id', 'lot_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_quants');
    }
};

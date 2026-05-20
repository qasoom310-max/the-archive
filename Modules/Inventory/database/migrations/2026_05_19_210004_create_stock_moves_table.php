<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The atom of the double-entry system: every inventory action is a
 * StockMove from a source to a destination location. `product_id` is a
 * logical reference (the catalogue is owned by another module) to keep
 * Inventory decoupled. `lot_name` / `barcode` scaffold traceability and
 * GS1 scanning for a later increment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_moves', function (Blueprint $table): void {
            $table->id();
            $table->string('reference');                 // e.g. WH/IN/00001
            $table->foreignId('stock_operation_type_id')->nullable()
                ->constrained('stock_operation_types')->nullOnDelete();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->decimal('product_qty', 14, 3)->default(0);
            $table->foreignId('source_location_id')->constrained('stock_locations');
            $table->foreignId('dest_location_id')->constrained('stock_locations');
            $table->string('state')->default('draft')->index();
            $table->string('lot_name')->nullable();      // traceability scaffold
            $table->string('barcode')->nullable();       // GS1 scaffold
            $table->dateTime('scheduled_at')->nullable()->index();
            $table->dateTime('done_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_moves');
    }
};

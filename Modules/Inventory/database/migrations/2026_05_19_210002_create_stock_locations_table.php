<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hierarchical locations (Warehouse > Aisle > Shelf via `parent_id`).
 * `type` drives the double-entry valuation rule. `parent_id` is a
 * logical self-reference (no DB FK — keeps SQLite/MySQL migrations
 * portable; integrity enforced in the model layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('complete_name')->nullable();   // cached path, e.g. WH/Stock/A
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->foreignId('warehouse_id')->nullable()
                ->constrained('warehouses')->nullOnDelete();
            $table->string('type')->default('internal')->index();
            $table->string('barcode')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_locations');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operation types (Odoo's stock.picking.type): Receipts, Delivery
 * Orders, Internal Transfers, PoS Orders. Each becomes a card on the
 * Inventory Overview dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_operation_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');             // incoming | outgoing | internal | pos
            $table->string('sequence_code');    // IN | OUT | INT | POS
            $table->foreignId('warehouse_id')->nullable()
                ->constrained('warehouses')->nullOnDelete();
            $table->foreignId('default_source_location_id')->nullable()
                ->constrained('stock_locations')->nullOnDelete();
            $table->foreignId('default_dest_location_id')->nullable()
                ->constrained('stock_locations')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(10);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_operation_types');
    }
};

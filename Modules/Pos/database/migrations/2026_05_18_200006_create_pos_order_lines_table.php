<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_order_id')->constrained('pos_orders')->cascadeOnDelete();
            $table->foreignId('pos_product_id')->nullable()
                ->constrained('pos_products')->nullOnDelete();
            $table->string('name'); // product name snapshot
            $table->decimal('qty', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount', 5, 2)->default(0); // percent
            $table->decimal('tax_rate', 5, 2)->default(0); // percent
            $table->decimal('subtotal', 12, 2)->default(0); // net of discount, pre-tax
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_lines');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental quotations — pre-sales estimates. Same shape as an order; once a
 * customer accepts, "Convert to order" spawns a draft order (order_id links
 * them) and the quotation is marked converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('rental_customers')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('rental_vehicles')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('rental_drivers')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('rental_branches')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('rental_orders')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('rate_type')->default('daily');
            $table->decimal('rate', 10, 3)->default(0);
            $table->integer('days')->default(0);
            $table->decimal('subtotal', 10, 3)->default(0);
            $table->decimal('discount', 10, 3)->default(0);
            $table->decimal('deposit', 10, 3)->default(0);
            $table->decimal('total', 10, 3)->default(0);
            $table->string('status')->default('draft'); // draft / sent / accepted / declined / converted
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('start_date');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_quotations');
    }
};

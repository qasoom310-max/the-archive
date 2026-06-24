<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental orders — the core contract: a customer takes a vehicle for a date
 * range at a chosen rate. State drives vehicle availability (active = rented,
 * closed/cancelled = freed) and the dashboard KPIs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('rental_customers')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('rental_vehicles')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('rental_drivers')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('rental_branches')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('rate_type')->default('daily');     // daily / weekly / monthly
            $table->decimal('rate', 10, 3)->default(0);        // unit rate (BHD)
            $table->integer('days')->default(0);               // computed duration
            $table->decimal('subtotal', 10, 3)->default(0);
            $table->decimal('discount', 10, 3)->default(0);
            $table->decimal('deposit', 10, 3)->default(0);
            $table->decimal('total', 10, 3)->default(0);
            $table->string('state')->default('draft');          // draft / active / closed / cancelled
            $table->string('payment_status')->default('unpaid'); // unpaid / paid
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('state');
            $table->index('payment_status');
            $table->index('start_date');
            $table->index('end_date');
            $table->index('customer_id');
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_orders');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Car replacements — a customer's vehicle is swapped for another (breakdown,
 * service, accident). Activating moves the replacement to rented and the
 * original to maintenance; closing frees both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_replacements', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('rental_orders')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('rental_customers')->nullOnDelete();
            $table->foreignId('original_vehicle_id')->nullable()->constrained('rental_vehicles')->nullOnDelete();
            $table->foreignId('replacement_vehicle_id')->nullable()->constrained('rental_vehicles')->nullOnDelete();
            $table->date('date')->nullable();
            $table->string('reason')->nullable();
            $table->string('status')->default('active'); // active / closed
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_replacements');
    }
};

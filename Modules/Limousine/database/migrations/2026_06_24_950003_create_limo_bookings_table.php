<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limousine trip bookings: a customer travels from a pickup to a dropoff
 * location at a date-time for a fare. Status drives the dashboard
 * (queue → confirmed → active → completed / cancelled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('limo_customers')->nullOnDelete();
            $table->foreignId('pickup_location_id')->nullable()->constrained('limo_locations')->nullOnDelete();
            $table->foreignId('dropoff_location_id')->nullable()->constrained('limo_locations')->nullOnDelete();
            $table->dateTime('pickup_at')->nullable();
            $table->integer('passengers')->nullable();
            $table->string('car_type')->nullable();      // sedan / suv / van / luxury
            $table->string('driver_name')->nullable();
            $table->decimal('fare', 10, 3)->default(0);
            $table->string('status')->default('queue');   // queue / confirmed / active / completed / cancelled
            $table->string('payment_status')->default('unpaid'); // unpaid / paid
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('payment_status');
            $table->index('pickup_at');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_bookings');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limousine quotations — trip estimates. "Convert to booking" spawns a queued
 * booking (booking_id links them) and marks the quote converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('limo_customers')->nullOnDelete();
            $table->foreignId('pickup_location_id')->nullable()->constrained('limo_locations')->nullOnDelete();
            $table->foreignId('dropoff_location_id')->nullable()->constrained('limo_locations')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('limo_bookings')->nullOnDelete();
            $table->dateTime('pickup_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('car_type')->nullable();
            $table->decimal('fare', 10, 3)->default(0);
            $table->string('status')->default('draft'); // draft / sent / accepted / declined / converted
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_quotations');
    }
};

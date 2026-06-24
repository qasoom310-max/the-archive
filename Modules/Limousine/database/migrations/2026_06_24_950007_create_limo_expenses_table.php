<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limousine running expenses (fuel, driver pay, maintenance, fees, …),
 * optionally tied to a booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->date('date')->nullable();
            $table->string('category')->default('fuel'); // fuel / driver_pay / maintenance / salaries / rent / fees / other
            $table->decimal('amount', 10, 3)->default(0);
            $table->string('payee')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained('limo_bookings')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_expenses');
    }
};

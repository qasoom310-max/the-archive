<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly booking-revenue figures imported from an older system, so the Sales
 * matrix and seasonal analysis reflect history that predates this app. One row
 * per car (by plate) per month per year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_revenue_history', function (Blueprint $table): void {
            $table->id();
            $table->string('plate_no')->nullable();
            $table->string('vehicle_label')->nullable();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1–12
            $table->decimal('amount', 12, 3)->default(0);
            $table->timestamps();

            $table->index('year');
            $table->index(['plate_no', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_revenue_history');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rental fleet. Each vehicle belongs to a branch, carries a full rate
 * card (daily / weekly / monthly) plus a security deposit, and a live status
 * (available / rented / maintenance / reserved) that drives the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // display, e.g. "Toyota Yaris 2023"
            $table->string('plate_no')->nullable();
            $table->foreignId('branch_id')->nullable()
                ->constrained('rental_branches')->nullOnDelete();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->integer('year')->nullable();
            $table->string('color')->nullable();
            $table->string('category')->nullable();        // economy / sedan / suv / luxury / van / bus
            $table->string('status')->default('available'); // available / rented / maintenance / reserved
            // BHD is a 3-decimal (fils) currency — money columns mirror that.
            $table->decimal('daily_rate', 10, 3)->default(0);
            $table->decimal('weekly_rate', 10, 3)->default(0);
            $table->decimal('monthly_rate', 10, 3)->default(0);
            $table->decimal('deposit', 10, 3)->default(0);
            $table->integer('odometer')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('status');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_vehicles');
    }
};

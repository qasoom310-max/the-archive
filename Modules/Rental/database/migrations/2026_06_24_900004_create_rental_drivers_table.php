<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drivers available for with-driver rentals / deliveries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_drivers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('cpr')->nullable();
            $table->string('license_no')->nullable();
            $table->string('nationality')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_drivers');
    }
};

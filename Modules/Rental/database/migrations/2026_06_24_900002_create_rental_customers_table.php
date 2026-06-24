<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental customers. A dedicated register (not the shared Contacts directory)
 * so it carries the identity fields a rental desk actually needs: CPR/ID,
 * driving-licence number and nationality.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('cpr')->nullable();          // Bahrain ID / CPR number
            $table->string('license_no')->nullable();   // driving licence number
            $table->string('nationality')->nullable();
            $table->text('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_customers');
    }
};

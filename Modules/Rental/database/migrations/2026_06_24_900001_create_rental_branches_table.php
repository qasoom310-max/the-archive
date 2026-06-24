<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental branches (e.g. Salihiya, Juffair). Every vehicle belongs to one,
 * and the dashboard reports availability per branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_branches');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pickup / dropoff locations (airport, hotels, areas). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('area')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_locations');
    }
};

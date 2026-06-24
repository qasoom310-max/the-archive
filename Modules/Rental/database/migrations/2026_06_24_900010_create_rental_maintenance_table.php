<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle maintenance records (service / repair / accident, …). While a record
 * is in progress its vehicle reads as under maintenance; marking it done frees
 * the vehicle again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_maintenance', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('rental_vehicles')->nullOnDelete();
            $table->date('date')->nullable();
            $table->string('type')->default('service'); // service / repair / oil_change / tyres / insurance / registration / accident / other
            $table->string('description')->nullable();
            $table->decimal('cost', 10, 3)->default(0);
            $table->integer('odometer')->nullable();
            $table->string('status')->default('scheduled'); // scheduled / in_progress / done
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('date');
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_maintenance');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's own selling windows for the ad calendar — an F1 weekend, a
 * wedding season, a closure — on top of the computed Islamic and national
 * ones. Core (per database): each business keeps its own rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->date('start_date');
            $table->date('end_date');
            // custom = a season worth advertising into; closed = the business
            // was not operating, so those days are "no data", not "no demand".
            $table->string('kind', 12)->default('custom');
            // Repeats on the same Gregorian dates every year.
            $table->boolean('recurs')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};

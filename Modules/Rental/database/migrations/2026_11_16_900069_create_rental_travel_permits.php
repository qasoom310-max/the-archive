<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel authorizations: the letter that lets a named driver take a company
 * car to another Gulf country.
 *
 * Each row keeps a SNAPSHOT of the car and the driver as they were on the day
 * it was issued (plate, make, model, year, colour, the driver's name and
 * personal number). The letter is a legal paper: reprinting it next month
 * must give the same letter even if the car was repainted or the driver's
 * record corrected since.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_travel_permits')) {
            return;
        }

        Schema::create('rental_travel_permits', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable()->unique();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->unsignedBigInteger('driver_id')->index();
            $table->date('issued_on');
            $table->date('valid_from');
            $table->date('valid_to')->index();
            $table->string('period', 16);
            // True when the chosen period ran past the car's registration and
            // the end date was pulled back to its last day.
            $table->boolean('shortened')->default(false);
            $table->string('plate_no');
            $table->string('make');
            $table->string('model');
            $table->unsignedSmallInteger('year');
            $table->string('color');
            $table->string('driver_name');
            $table->string('driver_cpr');
            $table->string('owner_name');
            $table->string('owner_no');
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_travel_permits');
    }
};

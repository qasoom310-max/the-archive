<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A leg now references an actual car from the Rent A Car fleet (rental_vehicles)
 * rather than a generic car type — the limo desk can only pick cars that are
 * available in Rent A Car. `vehicle` keeps a display snapshot of the car label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->unsignedBigInteger('car_id')->nullable()->after('service_type');
            $table->index('car_id');
        });
    }

    public function down(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropColumn('car_id');
        });
    }
};

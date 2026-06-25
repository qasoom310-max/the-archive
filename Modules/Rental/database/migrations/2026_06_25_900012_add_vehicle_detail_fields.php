<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra vehicle details surfaced on the order's vehicle read-out: fuel type and
 * the next scheduled maintenance (by date and by mileage). Make / model / year /
 * colour / category / odometer already exist on the vehicle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->string('fuel_type')->nullable()->after('color');
            $table->date('next_maintenance_date')->nullable()->after('odometer');
            $table->unsignedInteger('next_maintenance_mileage')->nullable()->after('next_maintenance_date');
        });
    }

    public function down(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->dropColumn(['fuel_type', 'next_maintenance_date', 'next_maintenance_mileage']);
        });
    }
};

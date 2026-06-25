<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuel shortfall charge: if the car comes back with less fuel than it went out
 * with, the desk enters the refuel amount at return. A flat service fee is added
 * on top (RentalOrder::FUEL_SERVICE_FEE) and the whole lot is billed on the
 * order total. This column stores the amount the employee entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->decimal('fuel_charge', 10, 3)->default(0)->after('return_fuel');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn('fuel_charge');
        });
    }
};

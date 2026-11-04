<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trip run in another company's car, driven by one of ours.
 *
 * The car is not in our fleet, so `car_id` stays empty: the company is named
 * here, the car itself is written in `vehicle` (as the fleet car's label is),
 * and `outside_cost` is what we pay that company for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            if (! Schema::hasColumn('limo_legs', 'outside_company')) {
                $table->string('outside_company', 150)->nullable();
            }
            if (! Schema::hasColumn('limo_legs', 'outside_cost')) {
                $table->decimal('outside_cost', 10, 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            if (Schema::hasColumn('limo_legs', 'outside_company')) {
                $table->dropColumn('outside_company');
            }
        });
        Schema::table('limo_legs', function (Blueprint $table): void {
            if (Schema::hasColumn('limo_legs', 'outside_cost')) {
                $table->dropColumn('outside_cost');
            }
        });
    }
};

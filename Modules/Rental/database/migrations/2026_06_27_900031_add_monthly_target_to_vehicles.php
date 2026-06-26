<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-car monthly sales target (BHD) — the revenue the owner wants each car to
 * earn in a month, shown against actual earnings on the car page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->decimal('monthly_target', 10, 3)->default(0)->after('deposit');
        });
    }

    public function down(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->dropColumn('monthly_target');
        });
    }
};

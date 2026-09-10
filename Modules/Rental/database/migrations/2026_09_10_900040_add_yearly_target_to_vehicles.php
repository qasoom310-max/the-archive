<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A car already carried a MONTHLY sales target. A year is not twelve of those:
 * a car is off the road for service, and the trade has seasons - Eid and the
 * F1 weekend are not a twelfth of the year each - so the owner needs to be
 * able to say what a car should earn over a whole year in its own right.
 *
 * Left at 0 (= not set), the fleet report falls back to twelve times the
 * monthly figure and says on screen that it is doing so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->decimal('yearly_target', 12, 3)->default(0)->after('monthly_target');
        });
    }

    public function down(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->dropColumn('yearly_target');
        });
    }
};

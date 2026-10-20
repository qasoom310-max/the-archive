<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we pay a COMMISSION driver for this trip, set when the trip is
 * assigned. A company driver is on a salary and has none.
 *
 * Kept on the trip, not read live from the driver's record: the driver's usual
 * figure only pre-fills it, and changing that figure later must not rewrite
 * what was agreed for trips already driven.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('limo_legs', 'driver_payment')) {
            return;
        }

        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->decimal('driver_payment', 10, 3)->nullable()->after('driver');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('limo_legs', 'driver_payment')) {
            Schema::table('limo_legs', function (Blueprint $table): void {
                $table->dropColumn('driver_payment');
            });
        }
    }
};

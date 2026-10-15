<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of the trip are being sold — two cars for the same journey is
 * units = 2 and costs twice the rate. Every existing trip is one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('limo_legs', 'units')) {
            return;
        }

        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->unsignedInteger('units')->default(1)->after('days');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('limo_legs', 'units')) {
            Schema::table('limo_legs', function (Blueprint $table): void {
                $table->dropColumn('units');
            });
        }
    }
};

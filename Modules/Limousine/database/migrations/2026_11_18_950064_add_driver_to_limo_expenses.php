<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whose bill an expense is: the driver who paid it and handed the paper in.
 * Empty for an office bill. A logical reference to the shared drivers table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_expenses') && ! Schema::hasColumn('limo_expenses', 'driver_id')) {
            Schema::table('limo_expenses', function (Blueprint $table): void {
                $table->unsignedBigInteger('driver_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('limo_expenses') && Schema::hasColumn('limo_expenses', 'driver_id')) {
            Schema::table('limo_expenses', function (Blueprint $table): void {
                $table->dropIndex(['driver_id']);
                $table->dropColumn('driver_id');
            });
        }
    }
};

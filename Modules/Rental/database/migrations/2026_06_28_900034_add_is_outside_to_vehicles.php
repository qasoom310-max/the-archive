<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a car as rented in from outside (not company-owned). Outside cars still
 * appear in the cars list and rent normally on orders, but are excluded from the
 * dashboard fleet KPIs / total-fleet count (which reflect owned cars only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->boolean('is_outside')->default(false)->after('active');
            $table->index('is_outside');
        });
    }

    public function down(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->dropColumn('is_outside');
        });
    }
};

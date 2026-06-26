<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track who first created each order so the orders list and order page can show
 * "Created by", alongside the full edit history in the activity trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn('created_by_user_id');
        });
    }
};

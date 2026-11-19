<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meet & greet on a limousine booking: someone waits for the passenger
 * (usually at arrivals) holding their name. The name to meet and the price
 * (5 BHD to start, changeable) live on the booking; the price is added to the
 * booking's total, so the invoice and the balance follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_bookings') && ! Schema::hasColumn('limo_bookings', 'meet_greet')) {
            Schema::table('limo_bookings', function (Blueprint $table): void {
                $table->boolean('meet_greet')->default(false);
                $table->string('meet_greet_name')->nullable();
                $table->decimal('meet_greet_price', 10, 3)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('limo_bookings') && Schema::hasColumn('limo_bookings', 'meet_greet')) {
            Schema::table('limo_bookings', function (Blueprint $table): void {
                $table->dropColumn(['meet_greet', 'meet_greet_name', 'meet_greet_price']);
            });
        }
    }
};

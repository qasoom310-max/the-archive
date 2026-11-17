<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the "balance still to pay" email went to the customer of a website
 * booking, about an hour before the trip. Set once, so it is never sent twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_bookings') && ! Schema::hasColumn('limo_bookings', 'balance_reminded_at')) {
            Schema::table('limo_bookings', function (Blueprint $table): void {
                $table->timestamp('balance_reminded_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('limo_bookings') && Schema::hasColumn('limo_bookings', 'balance_reminded_at')) {
            Schema::table('limo_bookings', function (Blueprint $table): void {
                $table->dropColumn('balance_reminded_at');
            });
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer actually paid on the website, apart from the price of the
 * trip. The fare finder lets a customer pay part now (20% inside Bahrain, 50%
 * leaving it) and the rest to the driver, so `total` alone can no longer say
 * both. Null on rows from before: they paid the whole total or nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_web_bookings') || Schema::hasColumn('rental_web_bookings', 'amount_paid')) {
            return;
        }

        Schema::table('rental_web_bookings', function (Blueprint $table): void {
            $table->decimal('amount_paid', 12, 3)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_web_bookings', 'amount_paid')) {
            Schema::table('rental_web_bookings', function (Blueprint $table): void {
                $table->dropColumn('amount_paid');
            });
        }
    }
};

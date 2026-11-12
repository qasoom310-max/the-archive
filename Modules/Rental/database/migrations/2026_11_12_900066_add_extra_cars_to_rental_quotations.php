<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quotation for more than one car: the first car stays in `vehicle_id` /
 * `rate`, every other one (up to nine more) is a {vehicle_id, rate} entry
 * here. Null on every quotation from before: one car.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_quotations') || Schema::hasColumn('rental_quotations', 'extra_cars')) {
            return;
        }

        Schema::table('rental_quotations', function (Blueprint $table): void {
            $table->json('extra_cars')->nullable()->after('rate');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_quotations', 'extra_cars')) {
            Schema::table('rental_quotations', function (Blueprint $table): void {
                $table->dropColumn('extra_cars');
            });
        }
    }
};

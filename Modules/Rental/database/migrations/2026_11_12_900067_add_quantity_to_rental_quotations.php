<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of the first car the quotation is for ("5 × Ford EcoSport"). The
 * other car lines keep their count in `extra_cars`. Existing quotations: 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_quotations') || Schema::hasColumn('rental_quotations', 'quantity')) {
            return;
        }

        Schema::table('rental_quotations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('quantity')->default(1)->after('vehicle_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_quotations', 'quantity')) {
            Schema::table('rental_quotations', function (Blueprint $table): void {
                $table->dropColumn('quantity');
            });
        }
    }
};

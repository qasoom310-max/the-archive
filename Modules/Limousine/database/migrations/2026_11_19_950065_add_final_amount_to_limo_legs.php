<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The price agreed with the customer for a trip (the "final deal"). When set it
 * is what the trip costs, VAT and extras included, and the trip's discount is
 * worked out from it. Empty = the price is worked out from the rate as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('limo_legs', 'final_amount')) {
            return;
        }

        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->decimal('final_amount', 12, 3)->nullable()->after('net_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('limo_legs', 'final_amount')) {
            return;
        }

        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropColumn('final_amount');
        });
    }
};

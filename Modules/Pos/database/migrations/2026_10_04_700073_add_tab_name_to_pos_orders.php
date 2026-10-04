<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pay-later order known by a NAME rather than a table on the floor plan
 * ("Abu Ali", "Outside bench") — opened from the floor plan's Unpaid orders
 * tab and settled later. Null for every ordinary order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->string('tab_name', 80)->nullable()->after('pos_table_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn('tab_name');
        });
    }
};

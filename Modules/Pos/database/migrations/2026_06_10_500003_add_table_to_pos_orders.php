<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bind an order to a table. `pos_table_id` null = a walk-in / quick sale
 * (no table). `guest_count` is the party size shown as the numerator in
 * the floor plan's "2/4".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('pos_table_id')->nullable()->after('pos_session_id')->index();
            $table->unsignedInteger('guest_count')->nullable()->after('pos_table_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn(['pos_table_id', 'guest_count']);
        });
    }
};

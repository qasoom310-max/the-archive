<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free spatial positioning for the floor plan. `pos_x` / `pos_y` are grid-cell
 * indices on the floor's canvas (NULL = "unplaced": the table shows in a tray
 * below the canvas until an admin drags it onto the plan). Per-floor by virtue
 * of `pos_floor_id` — the plan renders one floor at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_tables', function (Blueprint $table): void {
            $table->integer('pos_x')->nullable()->after('shape');
            $table->integer('pos_y')->nullable()->after('pos_x');
        });
    }

    public function down(): void
    {
        Schema::table('pos_tables', function (Blueprint $table): void {
            $table->dropColumn(['pos_x', 'pos_y']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The floor plan moved from free pixel drag to click-to-place onto grid cells
 * (one table per 96px cell, an 84px card centred with a 6px margin). Snap the
 * existing placed tables to the nearest cell + centre offset so the current
 * layout lines up with the squares immediately. Idempotent (re-snapping an
 * already-centred value is a no-op).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pos_tables', 'pos_x')) {
            return;
        }

        // offset = (CELL 96 - TABLE 84) / 2 = 6
        DB::statement('UPDATE pos_tables SET pos_x = ROUND(pos_x / 96.0) * 96 + 6 WHERE pos_x IS NOT NULL');
        DB::statement('UPDATE pos_tables SET pos_y = ROUND(pos_y / 96.0) * 96 + 6 WHERE pos_y IS NOT NULL');
    }

    public function down(): void
    {
        // Positions remain valid pixel coordinates — nothing to reverse.
    }
};

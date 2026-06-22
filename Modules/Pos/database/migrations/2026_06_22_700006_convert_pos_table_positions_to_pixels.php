<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The floor plan moved from a rigid 96px grid (pos_x/pos_y = cell indices) to
 * free pixel positioning (pos_x/pos_y = px from the canvas top-left) so tables
 * can sit anywhere — right beneath/beside each other. Rescale the few existing
 * placed tables by the old cell pitch (×96) so current layouts are preserved.
 * Runs once (migration-tracked); idempotent in the sense it never re-applies.
 */
return new class extends Migration
{
    private const CELL = 96;

    public function up(): void
    {
        if (! Schema::hasColumn('pos_tables', 'pos_x')) {
            return;
        }

        DB::table('pos_tables')->whereNotNull('pos_x')->update(['pos_x' => DB::raw('pos_x * ' . self::CELL)]);
        DB::table('pos_tables')->whereNotNull('pos_y')->update(['pos_y' => DB::raw('pos_y * ' . self::CELL)]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pos_tables', 'pos_x')) {
            return;
        }

        DB::table('pos_tables')->whereNotNull('pos_x')->update(['pos_x' => DB::raw('pos_x / ' . self::CELL)]);
        DB::table('pos_tables')->whereNotNull('pos_y')->update(['pos_y' => DB::raw('pos_y / ' . self::CELL)]);
    }
};

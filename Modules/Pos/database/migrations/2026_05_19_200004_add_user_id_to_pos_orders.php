<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit: stamp the cashier who created each order so the session's
 * "Sold" total attributes revenue to a specific user (Odoo POS does
 * the same via `user_id`). Kept nullable + plain-indexed (no DB-level
 * FK): SQLite cannot add a foreign key via ALTER, and the codebase
 * already treats cross-concern ids as logical refs (cf. Inventory
 * `stock_moves.product_id`). The value is always populated in app
 * logic at the single order-creation site; `pos_sessions.user_id`
 * already exists from the original table migration, so only
 * `pos_orders` needs the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->after('partner_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};

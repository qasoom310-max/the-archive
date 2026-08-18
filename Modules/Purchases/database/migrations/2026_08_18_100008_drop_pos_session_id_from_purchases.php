<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the POS-session tag from purchases — the feature it backed was removed
 * (a bill's session was never what the business wanted to track). Guarded so it
 * is a no-op on a database that never received the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchases', 'pos_session_id')) {
            return;
        }

        // SQLite refuses to drop a column an index still references, so the
        // index goes first (its own statement — MySQL is happy either way).
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropIndex(['pos_session_id']);
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn('pos_session_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchases', 'pos_session_id')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('pos_session_id')->nullable()->index()->after('user_id');
        });
    }
};

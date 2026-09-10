<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt now has to be CONFIRMED by an accountant.
 *
 * Writing a receipt says money was taken; it does not say the money arrived.
 * Cash can miss the drawer, and an online payment can fail after the screen
 * said otherwise — so every receipt starts unconfirmed and an accountant
 * closes it: cash by checking the drawer, bank methods by finding it on the
 * company statement (whose date is recorded).
 *
 * batch_id groups the receipts one bulk payment writes, so a lump sum across
 * forty invoices is ONE confirmation, not forty.
 *
 * Receipts already on file are backfilled as confirmed: dumping months of
 * history into the to-confirm queue on day one would bury the real work, and
 * that history was accepted under the old rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so a re-run only repeats the backfill, which is itself
        // scoped to rows still unconfirmed.
        if (! Schema::hasColumn('limo_receipts', 'confirmed_at')) {
            Schema::table('limo_receipts', function (Blueprint $table): void {
                $table->timestamp('confirmed_at')->nullable()->after('notes');
                // A name snapshot, not a FK: the row must still read right
                // after the confirming account is renamed or removed.
                $table->string('confirmed_by')->nullable()->after('confirmed_at');
                $table->date('statement_date')->nullable()->after('confirmed_by');
                $table->string('batch_id', 40)->nullable()->after('statement_date');
                $table->index('confirmed_at');
                $table->index('batch_id');
            });
        }

        DB::table('limo_receipts')->whereNull('confirmed_at')->update([
            'confirmed_at' => DB::raw('created_at'),
            'confirmed_by' => 'System — before confirmations existed',
        ]);
    }

    public function down(): void
    {
        // SQLite refuses to drop an indexed column, so the indexes go first.
        Schema::table('limo_receipts', function (Blueprint $table): void {
            $table->dropIndex(['confirmed_at']);
            $table->dropIndex(['batch_id']);
        });

        Schema::table('limo_receipts', function (Blueprint $table): void {
            $table->dropColumn(['confirmed_at', 'confirmed_by', 'statement_date', 'batch_id']);
        });
    }
};

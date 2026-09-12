<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of these answers the machine gave itself.
 *
 * `limo:match-driver-names` decides what it can on every deploy, and its rules
 * will get better than they were the first time. It must therefore be able to
 * revise ITS OWN answers without ever touching one a person gave — and telling
 * the two apart needs a flag, not a guess at the `decided_by` text.
 *
 * It also lets the screen say which rows were filled in automatically, so the
 * office can review the machine's work instead of discovering it in a report.
 *
 * Rows already on file were all written by the first automatic run or by the
 * matching screen; the run stamps `decided_by` as "Matched automatically",
 * which is what the backfill below reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_driver_aliases') || Schema::hasColumn('limo_driver_aliases', 'auto')) {
            return;
        }

        Schema::table('limo_driver_aliases', function (Blueprint $table): void {
            $table->boolean('auto')->default(false)->after('is_office');
        });

        // Anything the first run wrote is the machine's to revise.
        \Illuminate\Support\Facades\DB::table('limo_driver_aliases')
            ->where('decided_by', 'Matched automatically')
            ->update(['auto' => true]);
    }

    public function down(): void
    {
        if (Schema::hasTable('limo_driver_aliases') && Schema::hasColumn('limo_driver_aliases', 'auto')) {
            Schema::table('limo_driver_aliases', function (Blueprint $table): void {
                $table->dropColumn('auto');
            });
        }
    }
};

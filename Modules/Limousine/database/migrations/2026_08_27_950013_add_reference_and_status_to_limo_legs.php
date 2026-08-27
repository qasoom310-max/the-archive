<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give each trip leg its own identity.
 *
 * A booking used to be the unit of work: one reference, one status, however
 * many legs inside it. But each leg is dispatched separately — its own car, its
 * own driver, its own hour — so the office needs to see and move them one by
 * one. Each leg now carries a plain running reference (10000, 10001, …) and its
 * own status, while payment deliberately stays on the booking: the customer
 * settles the whole job, not a leg of it.
 *
 * Backfill numbers existing legs in a stable order (oldest first) so references
 * already quoted over the phone don't shuffle, and copies each leg's status down
 * from its parent booking. Quotation legs get a reference too — they share the
 * table — but no status, since a quote isn't dispatched.
 */
return new class extends Migration
{
    /** First reference handed out; ids below this are reserved. */
    private const START = 10000;

    public function up(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->string('reference', 32)->nullable()->after('sequence');
            $table->string('status', 16)->nullable()->after('reference');
        });

        // A unique index, not a unique() on the column: it has to tolerate the
        // NULLs that exist between adding the column and backfilling it.
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->unique('reference', 'limo_legs_reference_unique');
            $table->index('status', 'limo_legs_status_index');
        });

        $next = self::START;
        $rows = DB::table('limo_legs')->orderBy('id')->get(['id', 'legable_type', 'legable_id']);

        foreach ($rows as $row) {
            $status = null;
            if ($row->legable_type !== null && str_contains((string) $row->legable_type, 'LimoBooking')) {
                $status = DB::table('limo_bookings')->where('id', $row->legable_id)->value('status') ?? 'queue';
            }

            DB::table('limo_legs')->where('id', $row->id)->update([
                'reference' => (string) $next,
                'status' => $status,
            ]);
            $next++;
        }
    }

    public function down(): void
    {
        // SQLite cannot drop an indexed column in the same statement as its
        // index, so the indexes go first, in their own call.
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropUnique('limo_legs_reference_unique');
            $table->dropIndex('limo_legs_status_index');
        });

        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropColumn(['reference', 'status']);
        });
    }
};

<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use App\Erp\Backup\DatabaseBackup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;

/**
 * Trips entered in this ERP were numbered 41,7xx — the trip number came from
 * the row id, and imports had run the id counter far past the real sequence
 * (the old trips stop in the 26,2xx range). At the owner's request, those new
 * trips are renumbered to continue straight after the highest old number, in
 * the order they were created, and new trips now follow on the same way.
 *
 * Only trips numbered 30000 or more that were NOT brought over by the
 * historical import are touched. A coupon quoting a renumbered trip follows.
 * A database backup is taken first; every change is logged (server log and
 * activity log). Idempotent: nothing left to move means nothing happens.
 */
return new class extends Migration
{
    private const STRAY_FROM = 30000;

    public function up(): void
    {
        if (! Schema::hasTable('limo_legs') || ! Schema::hasColumn('limo_legs', 'reference')) {
            return;
        }

        $bookingType = (new LimoBooking())->getMorphClass();
        $hasImportedAt = Schema::hasColumn('limo_bookings', 'imported_at');

        $refs = DB::table('limo_legs')->whereNotNull('reference')->pluck('reference', 'id');
        $numeric = $refs->filter(static fn (mixed $r): bool => is_string($r) && ctype_digit($r));

        $strayIds = $numeric->filter(static fn (string $r): bool => (int) $r >= self::STRAY_FROM)->keys()->all();
        if ($strayIds === []) {
            return;
        }

        // Never renumber a trip the historical import brought over.
        $stray = DB::table('limo_legs')
            ->whereIn('id', $strayIds)
            ->orderBy('id')
            ->get(['id', 'reference', 'legable_type', 'legable_id'])
            ->filter(function (object $leg) use ($bookingType, $hasImportedAt): bool {
                if ($leg->legable_type !== $bookingType || ! $hasImportedAt) {
                    return true;
                }

                return DB::table('limo_bookings')->where('id', $leg->legable_id)->value('imported_at') === null;
            })
            ->values();

        if ($stray->isEmpty()) {
            return;
        }

        try {
            app(DatabaseBackup::class)->snapshot();
        } catch (Throwable $e) {
            // Never renumber without a way back.
            Log::error('Skipped renumbering new limousine trips: backup failed', ['error' => $e->getMessage()]);

            return;
        }

        $top = $numeric->map(static fn (string $r): int => (int) $r)->filter(static fn (int $n): bool => $n < self::STRAY_FROM)->max();
        $next = max(10000, (int) ($top ?? 9999) + 1);
        $taken = array_flip($numeric->values()->all());

        $map = [];
        DB::transaction(function () use ($stray, &$next, &$taken, &$map): void {
            foreach ($stray as $leg) {
                while (isset($taken[(string) $next])) {
                    $next++;
                }

                $old = (string) $leg->reference;
                $new = (string) $next;
                $taken[$new] = true;
                $next++;

                DB::table('limo_legs')->where('id', $leg->id)->update(['reference' => $new]);
                if (Schema::hasTable('limo_coupons') && Schema::hasColumn('limo_coupons', 'leg_reference')) {
                    DB::table('limo_coupons')->where('leg_reference', $old)->update(['leg_reference' => $new]);
                }

                $map[] = $old . ' → ' . $new;
            }
        });

        Log::info('Renumbered new limousine trips after the old sequence', [
            'database' => DB::connection()->getDatabaseName(),
            'renumbered' => count($map),
            'map' => $map,
        ]);

        app(ActivityLogger::class)->log(
            'updated',
            'Trip numbers',
            'New trips renumbered to follow the old sequence (' . count($map) . '): ' . implode(', ', $map),
        );
    }

    public function down(): void
    {
        // Restore from the backup taken in up() if the old numbers are needed.
    }
};

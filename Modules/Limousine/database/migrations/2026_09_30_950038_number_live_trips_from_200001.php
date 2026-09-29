<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use App\Erp\Backup\DatabaseBackup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Support\LiveEntry;

/**
 * Trips entered in this ERP are numbered from 200001, so they can never be
 * mistaken for (or clash with) an old-system trip.
 *
 * The owner asked for new trip numbers to start with 2. The previous attempt
 * (2026_09_29_950037) continued "after the old ones", on the belief that the
 * old trips stopped around 26,2xx — but the re-import had filled every
 * five-digit number up to ~41,7xx, so there was no room and the new trips
 * were pushed higher instead. Six digits starting with 2 cannot clash.
 *
 * Scope: only a database that went through the legacy import (it has
 * bookings with `imported_at` set), and only BOOKING trips entered live —
 * booking not imported AND created on or after the live cutover. They are
 * renumbered in creation order; a coupon quoting one follows. New trips then
 * carry on from the highest number (LimoLeg::nextReference()). Backup first,
 * every old → new pair logged. Idempotent.
 */
return new class extends Migration
{
    private const START = 200001;

    public function up(): void
    {
        if (! Schema::hasTable('limo_legs') || ! Schema::hasColumn('limo_bookings', 'imported_at')) {
            return;
        }

        // A database with no legacy import has no old numbers to keep clear
        // of, and keeps its own running sequence.
        if (! DB::table('limo_bookings')->whereNotNull('imported_at')->exists()) {
            return;
        }

        $bookingType = (new LimoBooking())->getMorphClass();

        $live = DB::table('limo_legs')
            ->join('limo_bookings', 'limo_bookings.id', '=', 'limo_legs.legable_id')
            ->where('limo_legs.legable_type', $bookingType)
            ->whereNull('limo_bookings.imported_at')
            ->where('limo_bookings.created_at', '>=', LiveEntry::since())
            ->orderBy('limo_legs.id')
            ->get(['limo_legs.id', 'limo_legs.reference'])
            // Already on the new numbering: leave it.
            ->reject(static fn (object $leg): bool => is_string($leg->reference) && ctype_digit($leg->reference) && (int) $leg->reference >= self::START)
            ->values();

        if ($live->isEmpty()) {
            return;
        }

        try {
            app(DatabaseBackup::class)->snapshot();
        } catch (Throwable $e) {
            Log::error('Skipped renumbering live limousine trips: backup failed', ['error' => $e->getMessage()]);

            return;
        }

        $taken = array_flip(DB::table('limo_legs')->whereNotNull('reference')->pluck('reference')->map(static fn (mixed $r): string => (string) $r)->all());
        $top = collect(array_keys($taken))
            ->filter(static fn (string $r): bool => ctype_digit($r) && (int) $r >= self::START)
            ->map(static fn (string $r): int => (int) $r)
            ->max();
        $next = max(self::START, (int) ($top ?? 0) + 1);

        $map = [];
        DB::transaction(function () use ($live, &$next, &$taken, &$map): void {
            $coupons = Schema::hasTable('limo_coupons') && Schema::hasColumn('limo_coupons', 'leg_reference');

            foreach ($live as $leg) {
                while (isset($taken[(string) $next])) {
                    $next++;
                }

                $old = (string) $leg->reference;
                $new = (string) $next;
                $taken[$new] = true;
                $next++;

                DB::table('limo_legs')->where('id', $leg->id)->update(['reference' => $new]);
                if ($coupons && $old !== '') {
                    DB::table('limo_coupons')->where('leg_reference', $old)->update(['leg_reference' => $new]);
                }

                $map[] = ($old !== '' ? $old : '—') . ' → ' . $new;
            }
        });

        Log::info('Renumbered live limousine trips from ' . self::START, [
            'database' => DB::connection()->getDatabaseName(),
            'renumbered' => count($map),
            'map' => $map,
        ]);

        app(ActivityLogger::class)->log(
            'updated',
            'Trip numbers',
            'Trips entered in the ERP renumbered from ' . self::START . ' (' . count($map) . '): ' . implode(', ', $map),
        );
    }

    public function down(): void
    {
        // Restore from the backup taken in up() if the old numbers are needed.
    }
};

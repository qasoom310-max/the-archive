<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use App\Erp\Backup\DatabaseBackup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoQuotation;

/**
 * Trips entered in this ERP lose the extra digit: 200015 becomes the next
 * five-digit number after the last old trip (~25,48x), so they start with 2
 * and run straight on from the history.
 *
 * Six digits were only ever a way round the 4xxxx block, and 2026_10_05_950039
 * closed that block up from 10000, so the room is there now. Booking trips are
 * renumbered in the order they were made, consecutively. Quotation trips drop
 * their number altogether: nobody ever sees it (a quote converted to a booking
 * gets fresh trip numbers), and it was what left gaps like 200015, 200021,
 * 200023 between the booking trips. LimoLeg no longer numbers them either.
 *
 * Each trip keeps its old number in `previous_reference`, so a search or a
 * WhatsApp lookup by 2000xx still finds it. A coupon follows its trip. Only in
 * a database that went through the legacy import and has six-digit trips;
 * refused (logged) if the new numbers would reach 30000. Backup first;
 * idempotent.
 */
return new class extends Migration
{
    private const CEILING = 30000;

    public function up(): void
    {
        if (! Schema::hasTable('limo_legs') || ! Schema::hasColumn('limo_legs', 'previous_reference')) {
            return;
        }

        if (! DB::table('limo_bookings')->whereNotNull('imported_at')->exists()) {
            return;
        }

        $long = DB::table('limo_legs')
            ->whereRaw('LENGTH(reference) >= 6')
            ->get(['id', 'reference', 'legable_type'])
            ->filter(static fn (object $leg): bool => is_string($leg->reference) && ctype_digit($leg->reference))
            ->sortBy(static fn (object $leg): int => (int) $leg->reference)
            ->values();

        if ($long->isEmpty()) {
            return;
        }

        $quoteType = (new LimoQuotation())->getMorphClass();
        [$quotes, $trips] = $long->partition(static fn (object $leg): bool => $leg->legable_type === $quoteType);

        $top = DB::table('limo_legs')
            ->whereRaw('LENGTH(reference) = 5')
            ->pluck('reference')
            ->filter(static fn (mixed $r): bool => is_string($r) && ctype_digit($r))
            ->map(static fn (string $r): int => (int) $r)
            ->max();
        $start = max(10000, (int) ($top ?? 9999) + 1);

        if ($start + $trips->count() > self::CEILING) {
            Log::warning('Left live limousine trips on six digits: five-digit numbers would reach ' . self::CEILING, [
                'database' => DB::connection()->getDatabaseName(),
                'start' => $start,
                'trips' => $trips->count(),
            ]);

            return;
        }

        // No backup, no renumbering — and the migration is not recorded as
        // run, so the next deploy tries again.
        app(DatabaseBackup::class)->snapshot();

        $map = [];
        DB::transaction(function () use ($quotes, $trips, $start, &$map): void {
            $coupons = Schema::hasTable('limo_coupons') && Schema::hasColumn('limo_coupons', 'leg_reference');

            foreach ($quotes as $leg) {
                DB::table('limo_legs')->where('id', $leg->id)->update([
                    'reference' => null,
                    'previous_reference' => $leg->reference,
                ]);
            }

            // Five-digit targets can never equal a six-digit number being
            // moved, and every target is above the highest five-digit one.
            $next = $start;
            foreach ($trips as $leg) {
                while (DB::table('limo_legs')->where('reference', (string) $next)->exists()) {
                    $next++;
                }

                $old = (string) $leg->reference;
                $new = (string) $next++;

                DB::table('limo_legs')->where('id', $leg->id)->update([
                    'reference' => $new,
                    'previous_reference' => $old,
                ]);
                if ($coupons) {
                    DB::table('limo_coupons')
                        ->where(static fn ($q) => $q->where('limo_leg_id', $leg->id)
                            ->orWhere(static fn ($q) => $q->whereNull('limo_leg_id')->where('leg_reference', $old)))
                        ->update(['leg_reference' => $new]);
                }

                $map[] = $old . ' → ' . $new;
            }
        });

        Log::info('Renumbered live limousine trips after the old ones', [
            'database' => DB::connection()->getDatabaseName(),
            'renumbered' => count($map),
            'quotation_numbers_cleared' => $quotes->count(),
            'map' => $map,
        ]);

        app(ActivityLogger::class)->log(
            'updated',
            'Trip numbers',
            'Trips entered in the ERP renumbered after the old ones (' . count($map) . '): ' . implode(', ', $map),
        );
    }

    public function down(): void
    {
        // Restore from the backup taken in up() if the old numbers are needed.
    }
};

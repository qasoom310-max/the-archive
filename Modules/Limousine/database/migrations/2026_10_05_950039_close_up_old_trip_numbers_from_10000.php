<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use App\Erp\Backup\DatabaseBackup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * No trip number starts with 3 or 4 any more: the five-digit numbers are
 * closed up so they run from 10000 with no gaps.
 *
 * The owner asked for the trips numbered 4xxxx to start with 2. They are old
 * trips from the 22 Sep re-import: the wipe before it deleted the earlier
 * trips but SQLite never reuses an id, so the re-imported ones were numbered
 * from ~26,215 up to ~41,7xx and 10000–26,214 stood empty. Closing the gap
 * moves every five-digit number down by the same amount, so 41697 becomes
 * ~25,4xx: the 4xxxx trips start with 2, the rest with 1 or 2.
 *
 * Every five-digit trip moves, booking and quotation alike (they share one
 * unique index), keeping their order. Six-digit live trips (200001+) are not
 * touched and new trips carry on from them. Each moved trip keeps its old
 * number in `previous_reference`, so re-importing an export taken before
 * this change recognises it instead of duplicating it. A coupon follows its
 * trip; a coupon whose trip was deleted (the 22 Sep wipe) is marked "old-…"
 * so it can't be read as naming the trip that now carries its number.
 *
 * Only in a database that went through the legacy import AND already numbers
 * its live trips from 200001: anywhere else the freed numbers would be handed
 * out again to new trips. Backup first (a failed backup throws, so the next
 * deploy retries); idempotent (a closed-up sequence maps onto itself).
 */
return new class extends Migration
{
    private const START = 10000;

    public function up(): void
    {
        if (! Schema::hasTable('limo_legs') || ! Schema::hasColumn('limo_bookings', 'imported_at')) {
            return;
        }

        if (! Schema::hasColumn('limo_legs', 'previous_reference')) {
            Schema::table('limo_legs', static function (Blueprint $table): void {
                $table->string('previous_reference', 32)->nullable()->after('reference')->index();
            });
        }

        if (! DB::table('limo_bookings')->whereNotNull('imported_at')->exists()) {
            return;
        }

        // New trips must already run on six digits, or they would be given
        // the numbers freed at the top.
        if (! DB::table('limo_legs')->whereRaw('LENGTH(reference) >= 6')->exists()) {
            return;
        }

        /** @var list<array{id: int, old: int}> $legs */
        $legs = DB::table('limo_legs')
            ->whereNotNull('reference')
            ->get(['id', 'reference'])
            ->filter(static fn (object $leg): bool => is_string($leg->reference)
                && strlen($leg->reference) === 5 && ctype_digit($leg->reference) && $leg->reference[0] !== '0')
            ->map(static fn (object $leg): array => ['id' => (int) $leg->id, 'old' => (int) $leg->reference])
            ->sortBy('old')
            ->values()
            ->all();

        $moves = [];
        foreach ($legs as $i => $leg) {
            $new = self::START + $i;
            if ($new !== $leg['old']) {
                $moves[] = ['id' => $leg['id'], 'old' => $leg['old'], 'new' => $new];
            }
        }

        if ($moves === []) {
            return;
        }

        // No backup, no renumbering — and the migration is not recorded as
        // run, so the next deploy tries again.
        app(DatabaseBackup::class)->snapshot();

        // Ascending order cannot clash on the unique index: each new number is
        // below its own old one and above every number already given out, and
        // a later trip's old number is above both.
        DB::transaction(function () use ($moves): void {
            $coupons = Schema::hasTable('limo_coupons') && Schema::hasColumn('limo_coupons', 'leg_reference');

            if ($coupons) {
                // Coupons outlived the trips deleted on 22 Sep; their numbers
                // are about to belong to other trips.
                DB::table('limo_coupons')
                    ->whereNotNull('limo_leg_id')
                    ->whereNotIn('limo_leg_id', DB::table('limo_legs')->select('id'))
                    ->whereRaw('LENGTH(leg_reference) = 5')
                    ->update(['leg_reference' => DB::raw(DB::getDriverName() === 'sqlite' ? "'old-' || leg_reference" : "CONCAT('old-', leg_reference)")]);
            }

            foreach ($moves as $move) {
                DB::table('limo_legs')->where('id', $move['id'])->update([
                    'reference' => (string) $move['new'],
                    'previous_reference' => (string) $move['old'],
                ]);
                if ($coupons) {
                    DB::table('limo_coupons')
                        ->where(static fn ($q) => $q->where('limo_leg_id', $move['id'])
                            ->orWhere(static fn ($q) => $q->whereNull('limo_leg_id')->where('leg_reference', (string) $move['old'])))
                        ->update(['leg_reference' => (string) $move['new']]);
                }
            }
        });

        $ranges = $this->ranges($moves);

        Log::info('Closed up old limousine trip numbers from ' . self::START, [
            'database' => DB::connection()->getDatabaseName(),
            'renumbered' => count($moves),
            'ranges' => $ranges,
        ]);

        app(ActivityLogger::class)->log(
            'updated',
            'Trip numbers',
            'Old trip numbers closed up from ' . self::START . ' (' . count($moves) . ' trips): ' . implode(', ', $ranges),
        );
    }

    /**
     * Old → new as runs ("26215–41697 → 10000–25482"), so the record of
     * fifteen thousand moves stays readable.
     *
     * @param  list<array{id: int, old: int, new: int}>  $moves
     * @return list<string>
     */
    private function ranges(array $moves): array
    {
        $out = [];
        $start = null;
        $prev = null;

        foreach ($moves as $move) {
            if ($prev !== null && $move['old'] === $prev['old'] + 1 && $move['new'] === $prev['new'] + 1) {
                $prev = $move;

                continue;
            }
            if ($start !== null && $prev !== null) {
                $out[] = $this->run($start, $prev);
            }
            $start = $move;
            $prev = $move;
        }
        if ($start !== null && $prev !== null) {
            $out[] = $this->run($start, $prev);
        }

        return $out;
    }

    /**
     * @param  array{id: int, old: int, new: int}  $from
     * @param  array{id: int, old: int, new: int}  $to
     */
    private function run(array $from, array $to): string
    {
        return $from === $to
            ? $from['old'] . ' → ' . $from['new']
            : $from['old'] . '–' . $to['old'] . ' → ' . $from['new'] . '–' . $to['new'];
    }

    public function down(): void
    {
        // The numbers come back from the backup taken in up(); only the
        // column is undone here.
        if (Schema::hasColumn('limo_legs', 'previous_reference')) {
            Schema::table('limo_legs', static function (Blueprint $table): void {
                $table->dropIndex(['previous_reference']);
            });
            Schema::table('limo_legs', static function (Blueprint $table): void {
                $table->dropColumn('previous_reference');
            });
        }
    }
};

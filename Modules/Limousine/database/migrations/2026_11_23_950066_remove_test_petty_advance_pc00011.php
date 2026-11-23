<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use App\Erp\Backup\DatabaseBackup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PC/00011 (459.10 BD to Abbas Naser Hamdan) was "Send to driver" pressed only
 * as a test, then re-dated into November, so it showed in that month's float
 * statement as real money out. The owner asked for it removed (2026-11-23).
 *
 * Touches only that exact advance: reference PC/00011, 459.10 BD, a driver
 * named "…Abbas…". Anything else (another database, a different amount) is
 * left alone. If it was ever settled, the expense rows settlement wrote for it
 * (their notes start with the reference) go too, or the expense reports would
 * keep the test money.
 */
return new class extends Migration
{
    private const REFERENCE = 'PC/00011';

    private const AMOUNT = 459.10;

    public function up(): void
    {
        if (! Schema::hasTable('limo_petty_advances') || ! Schema::hasTable('rental_drivers')) {
            return;
        }

        $advance = DB::table('limo_petty_advances as a')
            ->join('rental_drivers as d', 'd.id', '=', 'a.driver_id')
            ->where('a.reference', self::REFERENCE)
            ->whereRaw('LOWER(d.name) LIKE ?', ['%abbas%'])
            ->select('a.*', 'd.name as driver_name')
            ->first();
        if ($advance === null) {
            return;
        }
        if (abs((float) $advance->amount - self::AMOUNT) > 0.001) {
            Log::warning('Left petty advance ' . self::REFERENCE . ' alone: amount differs', ['amount' => $advance->amount]);

            return;
        }

        app(DatabaseBackup::class)->snapshot();

        $expenses = 0;
        DB::transaction(function () use ($advance, &$expenses): void {
            if ($advance->status === 'cleared' && Schema::hasTable('limo_expenses')) {
                $expenses = DB::table('limo_expenses')
                    ->where('notes', 'like', self::REFERENCE . '%')
                    ->where('payee', $advance->driver_name)
                    ->delete();
            }

            DB::table('limo_petty_lines')->where('advance_id', $advance->id)->delete();
            DB::table('limo_petty_advances')->where('id', $advance->id)->delete();
        });

        Log::info('Removed test petty advance ' . self::REFERENCE, ['advance' => (array) $advance, 'expenses' => $expenses]);
        app(ActivityLogger::class)->log(
            'deleted',
            'Petty cash ' . self::REFERENCE,
            'Test "Send to driver" of 459.10 BD to ' . $advance->driver_name . ' (dated ' . $advance->date . '); removed at the owner\'s request',
        );
    }

    public function down(): void
    {
        // A removed test record is not put back (the backup taken first has it).
    }
};

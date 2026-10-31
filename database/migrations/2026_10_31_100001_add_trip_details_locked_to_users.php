<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Operations staff whose view of a limousine trip stays short (time, car type,
 * customer, countries) until a car AND a driver are assigned to it in the
 * system — so the job can't be passed to a driver off the system, and the
 * customer's automatic driver-details message always has something to say.
 *
 * Set at the owner's request on 2026-10-31 for Abbas (by his email) and Ali
 * Mak (by the name his account carries). Core migration, so it reaches Main
 * AND every workspace; a no-op where an account is absent. Changed afterwards
 * under Settings → Driver details.
 */
return new class extends Migration
{
    private const EMAILS = ['hamdanabbas98@gmail.com'];

    /** Matched at the start of the account's name: "Ali Mak", "Ali Makki" … */
    private const NAME_PREFIXES = ['ali mak'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'trip_details_locked')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('trip_details_locked')->default(false);
            });
        }

        $locked = DB::table('users')
            ->where(function ($q): void {
                foreach (self::EMAILS as $email) {
                    $q->orWhereRaw('LOWER(email) = ?', [strtolower($email)]);
                }
                foreach (self::NAME_PREFIXES as $prefix) {
                    $q->orWhereRaw('LOWER(TRIM(name)) LIKE ?', [$prefix . '%']);
                }
            });

        $names = (clone $locked)->pluck('name')->all();
        $locked->update(['trip_details_locked' => true]);

        if ($names !== []) {
            Log::info('Trip details locked until a car and driver are assigned for: ' . implode(', ', $names) . ' in ' . DB::connection()->getDatabaseName());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'trip_details_locked')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('trip_details_locked');
            });
        }
    }
};

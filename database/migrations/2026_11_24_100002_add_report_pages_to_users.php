<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Report pages opened to one person, page by page (`users.report_pages`, a
 * JSON list): website fares, corporate rates, Daily Summary, Profit &
 * Expenses, Car income. Unlike `reports_access` it does NOT open Payroll or
 * the employee records.
 *
 * Granted at the owner's request on 2026-11-24 to Ali, Hasan and Abbas,
 * matched by the FIRST word of the name (so "Abbas Ali" is Abbas, and "Alia"
 * is nobody). Core migration: Main and every workspace. The names granted are
 * logged; the owner can change anyone on Settings → Users.
 */
return new class extends Migration
{
    private const FIRST_NAMES = ['ali', 'hasan', 'hassan', 'abbas'];

    private const PAGES = ['fares', 'corporate', 'daily', 'profit', 'car_income'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'report_pages')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->json('report_pages')->nullable();
            });
        }

        $granted = [];
        foreach (DB::table('users')->get(['id', 'name']) as $user) {
            $first = strtolower((string) strtok(trim((string) $user->name), " \t·-"));
            if (in_array($first, self::FIRST_NAMES, true)) {
                DB::table('users')->where('id', $user->id)->update(['report_pages' => json_encode(self::PAGES)]);
                $granted[] = (string) $user->name;
            }
        }

        if ($granted !== []) {
            Log::info('Report pages granted to: ' . implode(', ', $granted) . ' in ' . DB::connection()->getDatabaseName());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'report_pages')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('report_pages');
            });
        }
    }
};

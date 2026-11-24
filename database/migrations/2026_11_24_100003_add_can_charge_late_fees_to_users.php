<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a named person add a late-payment charge to a limousine customer
 * without being an admin (users.can_charge_late_fees). Granted at the owner's
 * request on 2026-11-24 to Via, matched by the first word of the name. Core
 * migration: Main and every workspace. The owner switches it per person on
 * Settings → Users.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'can_charge_late_fees')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('can_charge_late_fees')->default(false);
            });
        }

        $granted = [];
        foreach (DB::table('users')->get(['id', 'name']) as $user) {
            if (strtolower((string) strtok(trim((string) $user->name), " \t·-")) === 'via') {
                DB::table('users')->where('id', $user->id)->update(['can_charge_late_fees' => true]);
                $granted[] = (string) $user->name;
            }
        }

        if ($granted !== []) {
            Log::info('Late fees granted to: ' . implode(', ', $granted) . ' in ' . DB::connection()->getDatabaseName());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'can_charge_late_fees')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('can_charge_late_fees');
            });
        }
    }
};

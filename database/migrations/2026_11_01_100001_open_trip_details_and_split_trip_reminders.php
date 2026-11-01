<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's call on 2026-11-01: Abbas and Ali Mak see trip details normally
 * again — locking anyone (them included) is now a button on Settings → Users.
 *
 * The staff app's trip reminders used to go to whoever was locked. They are
 * their own switch now (users.trip_reminders), copied from the lock before it
 * is lifted, so Abbas and Ali keep being reminded about trips without a car
 * or driver. Core migration: Main and every workspace; a no-op where an
 * account is absent.
 */
return new class extends Migration
{
    /** The same two people the lock was put on (2026_10_31_100001). */
    private const EMAILS = ['hamdanabbas98@gmail.com'];

    private const NAME_PREFIXES = ['ali mak'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'trip_reminders')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('trip_reminders')->default(false);
            });
        }

        if (! Schema::hasColumn('users', 'trip_details_locked')) {
            return;
        }

        // Whoever was reminded keeps being reminded.
        DB::table('users')->where('trip_details_locked', true)->update(['trip_reminders' => true]);

        $opened = DB::table('users')
            ->where('trip_details_locked', true)
            ->where(function ($q): void {
                foreach (self::EMAILS as $email) {
                    $q->orWhereRaw('LOWER(email) = ?', [strtolower($email)]);
                }
                foreach (self::NAME_PREFIXES as $prefix) {
                    $q->orWhereRaw('LOWER(TRIM(name)) LIKE ?', [$prefix . '%']);
                }
            });

        $names = (clone $opened)->pluck('name')->all();
        $opened->update(['trip_details_locked' => false]);

        if ($names !== []) {
            Log::info('Trip details opened again for: ' . implode(', ', $names) . ' in ' . DB::connection()->getDatabaseName());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'trip_reminders')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('trip_reminders');
            });
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Who may give a Rent A Car order a pick-up date in the past.
 *
 * Every admin could. On 2026-11-16 the owner narrowed it to the super admin,
 * the accountant, and the account signed in as "via" — nobody else. The first
 * two are roles the system already knows; "via" is one named person, so it is
 * granted here by name (the username, or an e-mail starting "via@"), the same
 * way customer-type changes were on 2026-10-17. Core migration, so it reaches
 * Main AND every workspace; a no-op where that account is absent.
 */
return new class extends Migration
{
    private const USERNAMES = ['via'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'can_backdate_rentals')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('can_backdate_rentals')->default(false);
            });
        }

        $granted = DB::table('users')
            ->where(function ($q): void {
                foreach (self::USERNAMES as $name) {
                    $q->orWhereRaw('LOWER(TRIM(name)) = ?', [$name])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$name . '@%']);
                }
            });

        $names = (clone $granted)->pluck('name')->all();
        $granted->update(['can_backdate_rentals' => true]);

        if ($names !== []) {
            Log::info('Rental backdating granted to: ' . implode(', ', $names) . ' in ' . DB::connection()->getDatabaseName());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'can_backdate_rentals')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('can_backdate_rentals');
            });
        }
    }
};

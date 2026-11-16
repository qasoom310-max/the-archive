<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Who may print a travel authorization WITH the company stamp and the owner's
 * signature on it.
 *
 * A stamped authorization lets a named person take a company car across a
 * border, so the stamp is not for every member of staff. On 2026-11-16 the
 * owner named them: the super admin, and the accounts of Via, Ali, Abbas and
 * Hasan. The super admin is a role; the four are granted here by name (first
 * name, or an e-mail starting with it; Abbas also by his e-mail). The owner
 * can change the list afterwards on the Travel authorizations page, so a
 * wrong guess at a username here is put right on screen. Core migration: it
 * reaches Main AND every workspace, and is a no-op where an account is absent.
 */
return new class extends Migration
{
    private const EMAILS = ['hamdanabbas98@gmail.com'];

    private const FIRST_NAMES = ['via', 'ali', 'abbas', 'hasan'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'can_stamp_travel_permits')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('can_stamp_travel_permits')->default(false);
            });
        }

        $granted = DB::table('users')
            ->where(function ($q): void {
                foreach (self::EMAILS as $email) {
                    $q->orWhereRaw('LOWER(email) = ?', [strtolower($email)]);
                }
                foreach (self::FIRST_NAMES as $name) {
                    $q->orWhereRaw('LOWER(TRIM(name)) = ?', [$name])
                        ->orWhereRaw('LOWER(TRIM(name)) LIKE ?', [$name . ' %'])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$name . '@%']);
                }
            });

        $names = (clone $granted)->pluck('name')->all();
        $granted->update(['can_stamp_travel_permits' => true]);

        if ($names !== []) {
            Log::info('Travel authorization stamp granted to: ' . implode(', ', $names) . ' in ' . DB::connection()->getDatabaseName());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'can_stamp_travel_permits')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('can_stamp_travel_permits');
            });
        }
    }
};

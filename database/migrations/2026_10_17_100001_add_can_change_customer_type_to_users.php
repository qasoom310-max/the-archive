<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Lets named users switch an existing customer between Individual and Company,
 * which was super-admin only. Granted at the owner's request on 2026-10-17 to
 * Abbas (by his email) and to the accounts signed in as "ali" and "via" (by
 * username, or an e-mail starting "ali@" / "via@"). Core migration, so it
 * reaches Main AND every workspace; a no-op where an account is absent.
 */
return new class extends Migration
{
    private const EMAILS = ['hamdanabbas98@gmail.com'];

    private const USERNAMES = ['ali', 'via'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'can_change_customer_type')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('can_change_customer_type')->default(false);
            });
        }

        $granted = DB::table('users')
            ->where(function ($q): void {
                foreach (self::EMAILS as $email) {
                    $q->orWhereRaw('LOWER(email) = ?', [strtolower($email)]);
                }
                foreach (self::USERNAMES as $name) {
                    $q->orWhereRaw('LOWER(TRIM(name)) = ?', [$name])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$name . '@%']);
                }
            });

        $names = (clone $granted)->pluck('name')->all();
        $granted->update(['can_change_customer_type' => true]);

        if ($names !== []) {
            Log::info('Customer type change granted to: ' . implode(', ', $names) . ' in ' . DB::connection()->getDatabaseName());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'can_change_customer_type')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('can_change_customer_type');
            });
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full access to the Limousine petty-cash desk for a named user, the same as
 * the supervisor accountant has, without making them an accountant.
 *
 * Granted here to Abbas Hamdan (a supervisor at Wanaan), at the owner's
 * request on 2026-10-05. Core migration, so it reaches Main AND every
 * workspace (workspaces:migrate), and matched by email because each database
 * holds its own copy of the account. A no-op where the account is absent.
 */
return new class extends Migration
{
    private const GRANTED = ['hamdanabbas98@gmail.com'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'petty_cash_full')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('petty_cash_full')->default(false)->after('is_accountant');
            });
        }

        foreach (self::GRANTED as $email) {
            DB::table('users')
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->update(['petty_cash_full' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'petty_cash_full')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('petty_cash_full');
            });
        }
    }
};

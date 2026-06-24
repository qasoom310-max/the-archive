<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Designate the owner super admin. Idempotent and password-safe: it only
 * flips the flags on the matching email (raising `is_admin` too, since super
 * admin is a superset) and NEVER touches the password — so re-running on every
 * deploy can't lock anyone out. A no-op when the account doesn't exist in the
 * current database (e.g. a tenant that never had it / the test DB).
 */
return new class extends Migration
{
    private const OWNER_EMAIL = 'qasoom310@gmail.com';

    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'is_super_admin')) {
            return;
        }

        DB::table('users')
            ->where('email', self::OWNER_EMAIL)
            ->update(['is_admin' => true, 'is_super_admin' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'is_super_admin')) {
            return;
        }

        DB::table('users')
            ->where('email', self::OWNER_EMAIL)
            ->update(['is_super_admin' => false]);
    }
};

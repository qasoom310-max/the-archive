<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super-admin tier — a role ABOVE the regular admin. A super admin is a
 * strict superset (its row also carries `is_admin = true`, so every existing
 * `is_admin` gate keeps passing); `is_super_admin` is the extra flag that
 * gates owner-only powers (the dashboard system cards, the company business
 * type, and exemption from the admin 2FA step).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_super_admin');
        });
    }
};

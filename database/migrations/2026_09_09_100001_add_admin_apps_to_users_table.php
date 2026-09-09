<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Narrows an Administrator (never a super admin) to full access on
            // just these apps instead of every app in the database. Null/empty
            // = unrestricted — today's "Administrator" behaviour, unchanged.
            $table->json('admin_apps')->nullable()->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('admin_apps');
        });
    }
};

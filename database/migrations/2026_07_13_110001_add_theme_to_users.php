<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user appearance preference: light | dark | system (null = system).
 * Mirrors the per-user `language` column — a personal choice, not a
 * system-wide setting. Core table, so deploy's `migrate --force` applies it
 * to Main and `workspaces:migrate` backfills every tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('theme', 10)->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
    }
};

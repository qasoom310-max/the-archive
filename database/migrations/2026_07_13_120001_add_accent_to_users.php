<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user accent (brand) colour: yellow (default) | amber | orange | rose |
 * pink | violet | sky | emerald. null = the default brand yellow. Mirrors the
 * per-user `theme` column — a personal choice. Core table, so deploy's
 * `migrate --force` applies it to Main and `workspaces:migrate` backfills tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('accent', 20)->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('accent');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appearance (theme + accent) is a property of the DATABASE, not of the person:
 * Wanaan is yellow + light, Kaleem picks its own colour + dark, and everyone who
 * uses that database sees the same look. It now lives in the per-workspace
 * settings (`company.theme` / `company.accent`, see App\Erp\Branding\Appearance),
 * so the short-lived per-user columns are dead — drop them rather than leave a
 * second, silently-ignored source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'theme')) {
                $table->dropColumn('theme');
            }

            if (Schema::hasColumn('users', 'accent')) {
                $table->dropColumn('accent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('theme')->nullable();
            $table->string('accent')->nullable();
        });
    }
};

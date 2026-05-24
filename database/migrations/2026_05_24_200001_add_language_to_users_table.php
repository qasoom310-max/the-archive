<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user language preference. NULL means "follow the system default"
 * (the `company.language` ir_config_parameter), so existing rows stay
 * sane after this migration with no backfill. Width 5 covers any IETF
 * primary code we'd realistically support (`en`, `ar`, plus future
 * regional variants like `ar-EG`); SetLocale still whitelists against
 * the SUPPORTED list, so an unsupported value won't break the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('language', 5)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('language');
        });
    }
};

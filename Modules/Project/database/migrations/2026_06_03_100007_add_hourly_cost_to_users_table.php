<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a per-employee hourly cost so timesheets can compute labour cost.
 * Lives in the Project module (runs on `module:install project`) because
 * the field only has meaning once Project/timesheets exist — the core
 * `users` table stays lean until then. Nullable: existing users default
 * to the configured `project.default_hourly_cost`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('hourly_cost', 15, 2)->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('hourly_cost');
        });
    }
};

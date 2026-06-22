<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete for workspaces: deleting a database moves it to a 14-day trash
 * (file kept, restorable) instead of wiping it immediately. A daily sweep
 * (`workspaces:purge`-style scheduled task) permanently removes rows past the
 * retention window. `deleted_at` is the SoftDeletes column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'deleted_at')) {
            Schema::table('workspaces', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workspaces', 'deleted_at')) {
            Schema::table('workspaces', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};

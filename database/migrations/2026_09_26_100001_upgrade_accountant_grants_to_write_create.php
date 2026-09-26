<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Accountant role was broadened to Read + Write + Create on 2026-09-20,
 * but only grants written AFTER that date got the new rights — an accountant
 * provisioned earlier still carries Read-only rules, so e.g. creating a
 * rental receipt 403s. Upgrade every accountant's per-user group rules to
 * match the role today. Delete (perm_unlink) is never granted.
 *
 * Core migration, so it runs on Main and every workspace (workspaces:migrate).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ir_model_access')
            || ! Schema::hasTable('res_groups')
            || ! Schema::hasColumn('users', 'is_accountant')) {
            return;
        }

        $codes = DB::table('users')
            ->where('is_accountant', true)
            ->pluck('id')
            ->map(static fn (mixed $id): string => 'user:' . $id)
            ->all();

        if ($codes === []) {
            return;
        }

        $groupIds = DB::table('res_groups')->whereIn('code', $codes)->pluck('id')->all();

        if ($groupIds === []) {
            return;
        }

        DB::table('ir_model_access')
            ->whereIn('group_id', $groupIds)
            ->update(['perm_write' => true, 'perm_create' => true]);
    }

    public function down(): void
    {
        // Irreversible data fix — the old Read-only rules were the bug.
    }
};

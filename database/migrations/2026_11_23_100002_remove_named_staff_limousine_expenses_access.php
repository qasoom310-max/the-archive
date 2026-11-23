<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Later on 2026-11-23 the owner took back the Limousine expenses access given
 * to Via, Abbas, Hassan and Ali that morning (migration 100001).
 *
 * That access lived only in the shared "Limousine expenses" group
 * (`limousine_expenses`), which nothing else creates or uses, so the group,
 * its one rule and every membership are removed. Whatever role and apps each
 * person had before are untouched, so they are back where they were.
 * A no-op where the group does not exist (every database but Wanaan).
 */
return new class extends Migration
{
    private const GROUP_CODE = 'limousine_expenses';

    public function up(): void
    {
        foreach (['users', 'res_groups', 'res_group_user', 'ir_model_access'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $groupId = DB::table('res_groups')->where('code', self::GROUP_CODE)->value('id');
        if ($groupId === null) {
            return;
        }

        $names = DB::table('res_group_user')
            ->join('users', 'users.id', '=', 'res_group_user.user_id')
            ->where('res_group_user.group_id', $groupId)
            ->pluck('users.name')
            ->map(static fn ($n): string => (string) $n)
            ->all();

        DB::transaction(static function () use ($groupId): void {
            DB::table('res_group_user')->where('group_id', $groupId)->delete();
            DB::table('ir_model_access')->where('group_id', $groupId)->delete();
            DB::table('res_groups')->where('id', $groupId)->delete();
        });

        $who = $names === [] ? 'nobody' : implode(', ', $names);
        Log::info("Limousine expenses access removed from: {$who} in " . DB::connection()->getDatabaseName());

        try {
            app(ActivityLogger::class)->log(
                'user_updated',
                'Limousine expenses',
                'Access to expenses taken back from ' . $who . ' at the owner\'s request',
            );
        } catch (\Throwable) {
            // The audit line is a courtesy; removing the access is what matters.
        }
    }

    public function down(): void
    {
        // Not given back by a rollback.
    }
};

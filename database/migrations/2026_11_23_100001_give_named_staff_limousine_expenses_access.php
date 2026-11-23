<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * On 2026-11-23 the owner asked for Via, Abbas, Hassan and Ali to have the
 * Limousine expenses in the Wanaan database.
 *
 * They get it through a SHARED group ("Limousine expenses", code
 * `limousine_expenses`) holding one rule: view, add and edit expenses, never
 * delete. Not through their own per-user group, for two reasons:
 *  - Settings → Users rebuilds a person's own group from scratch on every
 *    edit, which would silently take this away again;
 *  - a write rule in a Staff member's own group makes Settings → Users read
 *    them back as a Supervisor.
 * Access rules add up across groups, so this sits beside whatever role and
 * apps they already have and changes neither.
 *
 * People are matched the way the travel-permit stamp list matched the same
 * four (first name, or an e-mail starting with it; Abbas and Hasan also by
 * their e-mail). Administrators are skipped: they already reach every app
 * (a scoped one without Limousine is logged, not widened). Only the Wanaan
 * database (company name contains "wanaan", or its VAT number); a no-op
 * everywhere else and where nobody matches. Idempotent.
 */
return new class extends Migration
{
    private const GROUP_CODE = 'limousine_expenses';

    private const MODEL = 'limousine.expense';

    private const EMAILS = ['hamdanabbas98@gmail.com', 'hasan.fuad@hotmail.com'];

    private const FIRST_NAMES = ['via', 'ali', 'abbas', 'hasan', 'hassan'];

    private const WANAAN_VAT = '220015215500002';

    public function up(): void
    {
        foreach (['users', 'res_groups', 'res_group_user', 'ir_model_access', 'ir_config_parameter'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        if (! $this->isWanaan()) {
            return;
        }

        $people = DB::table('users')
            ->where(function ($q): void {
                foreach (self::EMAILS as $email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                foreach (self::FIRST_NAMES as $name) {
                    $q->orWhereRaw('LOWER(TRIM(name)) = ?', [$name])
                        ->orWhereRaw('LOWER(TRIM(name)) LIKE ?', [$name . ' %'])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$name . '@%']);
                }
            })
            ->get(['id', 'name', 'is_admin']);

        $staff = $people->filter(static fn (object $u): bool => ! (bool) $u->is_admin);
        foreach ($people->filter(static fn (object $u): bool => (bool) $u->is_admin) as $admin) {
            Log::info("Limousine expenses: {$admin->name} is an administrator, left as is");
        }

        if ($staff->isEmpty()) {
            return;
        }

        $now = now();

        $groupId = DB::table('res_groups')->where('code', self::GROUP_CODE)->value('id');
        if ($groupId === null) {
            $groupId = DB::table('res_groups')->insertGetId([
                'name' => 'Limousine expenses',
                'code' => self::GROUP_CODE,
                'description' => 'View, add and edit Limousine expenses (no delete). Given on the owner\'s request, 2026-11-23.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rule = [
            'perm_read' => true,
            'perm_write' => true,
            'perm_create' => true,
            'perm_unlink' => false,
            'updated_at' => $now,
        ];
        if (DB::table('ir_model_access')->where('group_id', $groupId)->where('model', self::MODEL)->exists()) {
            DB::table('ir_model_access')->where('group_id', $groupId)->where('model', self::MODEL)->update($rule);
        } else {
            DB::table('ir_model_access')->insert($rule + [
                'name' => 'Limousine expenses: ' . self::MODEL,
                'model' => self::MODEL,
                'group_id' => $groupId,
                'created_at' => $now,
            ]);
        }

        $added = [];
        foreach ($staff as $user) {
            $linked = DB::table('res_group_user')
                ->where('group_id', $groupId)
                ->where('user_id', $user->id)
                ->exists();
            if (! $linked) {
                DB::table('res_group_user')->insert(['group_id' => $groupId, 'user_id' => $user->id]);
                $added[] = (string) $user->name;
            }
        }

        if ($added === []) {
            return;
        }

        Log::info('Limousine expenses access given to: ' . implode(', ', $added) . ' in ' . DB::connection()->getDatabaseName());

        try {
            app(ActivityLogger::class)->log(
                'user_updated',
                'Limousine expenses',
                'Access to view, add and edit expenses given to ' . implode(', ', $added) . ' at the owner\'s request',
            );
        } catch (\Throwable) {
            // The audit line is a courtesy; the access itself is what matters.
        }
    }

    private function isWanaan(): bool
    {
        $values = DB::table('ir_config_parameter')
            ->whereIn('key', ['company.name', 'company.vat_number'])
            ->pluck('value', 'key');

        $name = strtolower((string) ($values['company.name'] ?? ''));
        $vat = preg_replace('/\D/', '', (string) ($values['company.vat_number'] ?? ''));

        return str_contains($name, 'wanaan') || $vat === self::WANAAN_VAT;
    }

    public function down(): void
    {
        // Access given on request is not taken back by a rollback.
    }
};

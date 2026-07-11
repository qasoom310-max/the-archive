<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every login authenticates against Main, but a user created INSIDE a workspace
 * (its own Users screen) only exists in that tenant's database — so they can't
 * sign in at all. This backfills a Main login for each such user: matched by
 * email, their existing password hash copied verbatim (so their current
 * password keeps working), and — if they live in exactly one workspace — locked
 * to it so they land straight in their business.
 *
 * Dry run by default; pass --apply to write. Idempotent (safe to re-run).
 */
final class BackfillWorkspaceLogins extends Command
{
    protected $signature = 'users:backfill-logins {--apply : Actually write (default lists the plan only)}';

    protected $description = 'Give every workspace-only user a Main login so they can sign in.';

    public function handle(WorkspaceManager $workspaces): int
    {
        $apply = (bool) $this->option('apply');

        // Emails that can already sign in (they exist in Main).
        $mainEmails = User::query()->whereNotNull('email')->pluck('email')
            ->map(static fn ($e): string => mb_strtolower((string) $e))->all();

        // Gather every real, email-bearing user across the tenant databases,
        // remembering which workspaces each one lives in.
        /** @var array<string, array{name: string, password: string, tenants: list<int>}> $found */
        $found = [];
        $tenants = Workspace::query()->where('is_main', false)->get();

        foreach ($tenants as $ws) {
            $path = $ws->databasePath();
            if ($path === null || ! is_file($path)) {
                continue;
            }
            $workspaces->withTenant($path, function () use ($ws, &$found): void {
                foreach (User::query()->whereNotNull('email')->get() as $u) {
                    $email = mb_strtolower((string) $u->email);
                    // Skip framework/demo seed accounts.
                    if ($email === '' || str_ends_with($email, '@example.com')) {
                        continue;
                    }
                    if (! isset($found[$email])) {
                        $found[$email] = ['name' => (string) $u->name, 'password' => (string) $u->password, 'tenants' => []];
                    }
                    $found[$email]['tenants'][] = (int) $ws->id;
                }
            });
        }

        $count = 0;
        foreach ($found as $email => $info) {
            if (in_array($email, $mainEmails, true)) {
                continue; // already able to sign in
            }

            $tenantIds = array_values(array_unique($info['tenants']));
            $single = count($tenantIds) === 1;
            $homeId = $single ? $tenantIds[0] : null;
            $verb = $apply ? 'LINKED' : 'PLAN';

            if ($single) {
                $this->line("{$verb}  {$info['name']} <{$email}>  → login locked to workspace #{$homeId}");
            } else {
                $this->line("{$verb}  {$info['name']} <{$email}>  → Main admin (in " . count($tenantIds) . ' workspaces)');
            }

            if ($apply) {
                $this->writeMainShell($email, $info['name'], $info['password'], ! $single, $homeId);

                // Lock the tenant copy too, so its in-workspace database switcher
                // hides (a workspace super admin would otherwise still see it).
                if ($single) {
                    $target = $tenants->firstWhere('id', $homeId);
                    if ($target !== null && $target->databasePath() !== null) {
                        $workspaces->withTenant((string) $target->databasePath(), static function () use ($email, $homeId): void {
                            DB::table('users')->where('email', $email)->update(['home_workspace_id' => $homeId]);
                        });
                    }
                }
            }

            $count++;
        }

        $this->info(($apply ? 'Linked ' : 'Would link ') . "{$count} user(s)." . ($apply ? '' : '  Re-run with --apply to write.'));

        return self::SUCCESS;
    }

    /**
     * Upsert the Main login shell via the query builder so the ALREADY-hashed
     * password is stored verbatim (the model's `hashed` cast would re-hash it).
     */
    private function writeMainShell(string $email, string $name, string $passwordHash, bool $isAdmin, ?int $homeWorkspaceId): void
    {
        $now = now();
        $data = [
            'name' => $name,
            'password' => $passwordHash,
            'is_admin' => $isAdmin,
            'is_super_admin' => false,
            'home_workspace_id' => $homeWorkspaceId,
            'updated_at' => $now,
        ];

        if (DB::table('users')->where('email', $email)->exists()) {
            DB::table('users')->where('email', $email)->update($data);
        } else {
            DB::table('users')->insert($data + ['email' => $email, 'created_at' => $now]);
        }
    }
}

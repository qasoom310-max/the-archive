<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Admin\StaffRole;
use App\Erp\Admin\UserProvisioner;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Ir\IrModule;
use App\Models\Workspace;
use App\Erp\Enums\ModuleState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Create or repair a NON-admin staff login (idempotent) from the CLI — the
 * command-line twin of Settings → Users, for when the screen can't be reached
 * (nobody logged in with the right tier, or the account is a global one that
 * the Users tab renders read-only from inside a workspace).
 *
 * Goes through {@see UserProvisioner} rather than writing rows directly, so
 * the per-user group + `ir_model_access` grants match exactly what the UI
 * would have produced for that role.
 *
 *   php artisan user:ensure account@example.com 'a-strong-pass' \
 *       --name=Prejith --role=accountant
 *
 * With no email/password it no-ops, so it is safe to leave in a deploy chain.
 */
final class EnsureStaffUserCommand extends Command
{
    protected $signature = 'user:ensure
        {email? : The login email}
        {password? : Plain password (min 8); omit to keep the existing one}
        {--name= : Display name (defaults to the part before the @)}
        {--role=staff : staff|supervisor|accountant|admin|super}
        {--apps= : Comma-separated app module names to grant; default = every installed app}
        {--databases=all : "all", or comma-separated workspace ids (Main included)}
        {--lock-to= : Workspace id to LOCK the account to (they can never leave it)}';

    protected $description = 'Create or reset a staff login with a role + app grants (idempotent).';

    public function handle(UserProvisioner $provisioner): int
    {
        $email = trim((string) $this->argument('email'));
        $password = (string) $this->argument('password');

        if ($email === '') {
            $this->info('user:ensure skipped — no email provided.');

            return self::SUCCESS;
        }

        if ($password !== '' && mb_strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $role = StaffRole::tryFrom((string) $this->option('role'));
        if ($role === null) {
            $this->error('Unknown role. Use one of: ' . implode(', ', array_map(
                static fn (StaffRole $r): string => $r->value,
                StaffRole::all(),
            )));

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name'));
        if ($name === '') {
            $name = (string) strstr($email, '@', true) ?: $email;
        }

        $apps = $this->resolveApps();

        // Locked account: the real row lives inside one workspace, Main keeps
        // only a login shell that routes them straight back into it.
        $lockTo = $this->option('lock-to');
        if ($lockTo !== null && $lockTo !== '') {
            $user = $provisioner->provisionLocked(
                $name,
                $email,
                $password !== '' ? $password : null,
                (int) $lockTo,
                $role,
                $apps,
            );

            $this->info("Locked {$role->value} ensured: {$email} (workspace {$lockTo}, id {$user->getKey()}).");

            return self::SUCCESS;
        }

        if ($password === '') {
            $this->error('A password is required when creating an unlocked account.');

            return self::FAILURE;
        }

        $workspaceIds = $this->resolveWorkspaceIds();

        $provisioner->provision($name, $email, $password, $apps, $workspaceIds, $role);

        $where = $workspaceIds === [] ? 'this database' : count($workspaceIds) . ' database(s)';
        $this->info("{$role->label()} ensured: {$email} in {$where}.");
        $this->line('Apps granted: ' . ($apps === [] ? '(none — role bypasses the ACL)' : implode(', ', $apps)));

        return self::SUCCESS;
    }

    /**
     * The apps to grant on. Default = every installed application module; each
     * database's own business type still filters this inside grantApps().
     *
     * @return list<string>
     */
    private function resolveApps(): array
    {
        $given = trim((string) $this->option('apps'));

        if ($given !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $given))));
        }

        if (! Schema::hasTable('ir_module')) {
            return [];
        }

        return IrModule::query()
            ->where('application', true)
            ->where('state', ModuleState::Installed)
            ->orderBy('sequence')
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }

    /**
     * Which databases to create the account in. "all" = Main plus every
     * workspace, which is what "this person works everywhere" means.
     *
     * @return list<int>
     */
    private function resolveWorkspaceIds(): array
    {
        if (! Schema::hasTable('workspaces')) {
            return [];
        }

        $given = trim((string) $this->option('databases'));

        if ($given !== '' && strtolower($given) !== 'all') {
            return array_values(array_map(
                static fn (string $id): int => (int) trim($id),
                array_filter(explode(',', $given), static fn (string $id): bool => trim($id) !== ''),
            ));
        }

        return app(WorkspaceManager::class)->all()
            ->map(static fn (Workspace $workspace): int => (int) $workspace->getKey())
            ->values()
            ->all();
    }
}

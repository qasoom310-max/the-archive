<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Admin\UserProvisioner;
use Illuminate\Console\Command;

/**
 * Permanently delete a login (and its per-user access group) from one or
 * every database — the CLI twin of {@see \App\Console\Commands\EnsureStaffUserCommand}
 * ("user:ensure"), for the opposite job: a GLOBAL account (shared across every
 * database) that the Users tab can only ever delete on Main, never from
 * inside a workspace (deleting it there is refused on purpose — its identity
 * belongs to Main).
 *
 *   php artisan user:remove admin@example.com
 *   php artisan user:remove admin@example.com --databases=3,7
 *
 * With no matching account in a given database, that database is silently
 * skipped — safe to run without knowing in advance where the email exists.
 */
final class RemoveUserCommand extends Command
{
    protected $signature = 'user:remove
        {email : The login email to remove}
        {--databases=all : "all", or comma-separated workspace ids (Main included)}';

    protected $description = 'Permanently delete a login and its access grants from one or every database.';

    public function handle(UserProvisioner $provisioner): int
    {
        $email = trim((string) $this->argument('email'));

        if ($email === '') {
            $this->error('An email is required.');

            return self::FAILURE;
        }

        $onlyIds = $this->resolveWorkspaceIds();

        $removedFrom = $provisioner->deleteEverywhere($email, $onlyIds);

        if ($removedFrom === []) {
            $this->info("No account found for {$email} in the selected database(s).");

            return self::SUCCESS;
        }

        $this->info("Removed {$email} from: " . implode(', ', $removedFrom));

        return self::SUCCESS;
    }

    /**
     * null = every database (Main included) — the default and the common case
     * ("remove this account from any db"). A comma-separated list restricts to
     * just those workspace ids.
     *
     * @return list<int>|null
     */
    private function resolveWorkspaceIds(): ?array
    {
        $given = trim((string) $this->option('databases'));

        if ($given === '' || strtolower($given) === 'all') {
            return null;
        }

        return array_values(array_map(
            static fn (string $id): int => (int) trim($id),
            array_filter(explode(',', $given), static fn (string $id): bool => trim($id) !== ''),
        ));
    }
}

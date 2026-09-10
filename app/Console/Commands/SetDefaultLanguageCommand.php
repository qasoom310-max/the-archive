<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Settings\Setting;
use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Set `company.language` — the fallback locale for a guest on the login
 * page and any signed-in user with no personal `language` preference (see
 * {@see \App\Http\Middleware\SetLocale}) — on one or every database.
 *
 * This is the ONE setting the Settings page's "Language" row can never
 * reach: that row is deliberately a PERSONAL preference (it reads/writes
 * `Auth::user()->language`, never the system row — see
 * {@see \App\Livewire\Pages\SettingsPage}), so there is no in-app control
 * for the system-wide default a brand-new visitor gets. This command is it.
 *
 *   php artisan language:set-default en
 *   php artisan language:set-default en --databases=3,7
 *
 * Idempotent — a database already on the requested code is left alone (and
 * still reported, so a run always accounts for every database it touched).
 */
final class SetDefaultLanguageCommand extends Command
{
    private const SUPPORTED = ['en', 'ar'];

    protected $signature = 'language:set-default
        {code=en : The default locale code (en or ar)}
        {--databases=all : "all", or comma-separated workspace ids (Main included)}';

    protected $description = 'Set the system-wide default language (company.language) on one or every database.';

    public function handle(WorkspaceManager $workspaces): int
    {
        $code = strtolower(trim((string) $this->argument('code')));

        if (! in_array($code, self::SUPPORTED, true)) {
            $this->error('Unsupported code "' . $code . '" — use one of: ' . implode(', ', self::SUPPORTED));

            return self::FAILURE;
        }

        if (! Schema::hasTable('workspaces')) {
            Setting::set('company.language', $code);
            $this->info("Set company.language={$code} on this database.");

            return self::SUCCESS;
        }

        $targets = $workspaces->all();
        $onlyIds = $this->resolveWorkspaceIds();
        if ($onlyIds !== null) {
            $targets = $targets->whereIn('id', $onlyIds);
        }

        $updated = [];

        foreach ($targets as $workspace) {
            if ($workspace->is_main) {
                $workspaces->withMain(static function () use ($code): void {
                    Setting::set('company.language', $code);
                });
                $updated[] = 'Main';

                continue;
            }

            $path = $workspace->databasePath();
            if ($path === null || ! is_file($path)) {
                continue;
            }

            $workspaces->withTenant($path, static function () use ($code): void {
                Setting::set('company.language', $code);
            });
            $updated[] = $workspace->name;
        }

        if ($updated === []) {
            $this->info('No matching database found.');

            return self::SUCCESS;
        }

        $this->info("Set company.language={$code} on: " . implode(', ', $updated));

        return self::SUCCESS;
    }

    /**
     * null = every database (Main included) — the default. A comma-separated
     * list restricts to just those workspace ids.
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

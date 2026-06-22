<?php

declare(strict_types=1);

namespace App\Erp\Tenancy;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Database\Seeders\AuthSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The database manager. Provisions, lists, activates and deletes workspaces
 * (separate SQLite "databases"). The active workspace's file becomes the
 * default DB connection for the request — see
 * {@see \App\Http\Middleware\SetActiveWorkspace}. Main is a no-op (it IS the
 * default connection / today's data).
 */
final class WorkspaceManager
{
    /** Days a trashed (deleted) workspace is kept before it is purged for good. */
    public const RETENTION_DAYS = 14;

    /** Where tenant SQLite files live (relative to the public storage disk root). */
    private function directory(): string
    {
        return storage_path('app/workspaces');
    }

    /**
     * The built-in Main workspace row (today's data). Created on first use.
     */
    public function ensureMain(): Workspace
    {
        return Workspace::query()->firstOrCreate(
            ['is_main' => true],
            ['name' => 'Main', 'slug' => 'main', 'database' => null],
        );
    }

    /**
     * @return Collection<int, Workspace> Main first, then alphabetical.
     */
    public function all(): Collection
    {
        $this->ensureMain();

        return Workspace::query()->orderByDesc('is_main')->orderBy('name')->get();
    }

    public function find(int $id): ?Workspace
    {
        return Workspace::query()->find($id);
    }

    /** Find a workspace including trashed ones (for restore / purge). */
    public function findAny(int $id): ?Workspace
    {
        return Workspace::withTrashed()->find($id);
    }

    /**
     * Trashed (soft-deleted) workspaces awaiting restore or purge.
     *
     * @return Collection<int, Workspace> Most recently deleted first.
     */
    public function trashed(): Collection
    {
        return Workspace::onlyTrashed()->orderByDesc('deleted_at')->get();
    }

    /**
     * The workspace selected by the request cookie, defaulting to Main.
     */
    public function current(): Workspace
    {
        $cookie = request()->cookie(Workspace::COOKIE);

        if (is_string($cookie) && ctype_digit($cookie)) {
            $workspace = Workspace::query()->find((int) $cookie);
            if ($workspace !== null) {
                return $workspace;
            }
        }

        return $this->ensureMain();
    }

    /**
     * Point the default DB connection at this workspace for the rest of the
     * request. Main is a deliberate no-op (default stays the Main DB). Cache
     * is namespaced per workspace so per-workspace settings can't bleed.
     */
    public function activate(Workspace $workspace): void
    {
        if ($workspace->is_main) {
            return;
        }

        $path = $workspace->databasePath();
        if ($path === null || ! is_file($path)) {
            return; // missing file → fail safe to Main rather than 500
        }

        config(['cache.prefix' => 'ws' . $workspace->id . '_']);
        config(['database.connections.tenant.database' => $path]);
        DB::purge('tenant');
        config(['database.default' => 'tenant']);
        DB::setDefaultConnection('tenant');
    }

    /**
     * Run a callback with the default connection pointed at a tenant file,
     * restoring the previous default afterwards. Used while provisioning.
     *
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withTenant(string $path, Closure $callback): mixed
    {
        $previous = DB::getDefaultConnection();

        config(['database.connections.tenant.database' => $path]);
        DB::purge('tenant');
        config(['database.default' => 'tenant']);
        DB::setDefaultConnection('tenant');

        try {
            return $callback();
        } finally {
            config(['database.default' => $previous]);
            DB::setDefaultConnection($previous);
            DB::purge('tenant');
        }
    }

    /**
     * Create a brand-new workspace: an isolated SQLite file migrated with the
     * full schema, every module installed, and the Main admins copied in
     * (matched by email) so they can switch straight in.
     *
     * @param  list<string>|null  $modules  module names to install; null = all discovered
     */
    public function provision(string $name, User $owner, ?array $modules = null): Workspace
    {
        $this->ensureMain();

        $slug = $this->uniqueSlug($name);
        $filename = $slug . '_' . bin2hex(random_bytes(4)) . '.sqlite';

        if (! is_dir($this->directory())) {
            mkdir($this->directory(), 0775, true);
        }

        $path = $this->directory() . DIRECTORY_SEPARATOR . $filename;
        touch($path); // empty SQLite database

        // Capture Main admins BEFORE swapping the connection (so they can be
        // copied into the new DB and switch in by email later).
        /** @var list<array{name: string, email: string|null, password: string}> $admins */
        $admins = User::query()->where('is_admin', true)
            ->get(['name', 'email', 'password'])
            ->map(static fn (User $u): array => [
                'name' => (string) $u->name,
                'email' => $u->email,
                'password' => (string) $u->password,
            ])->all();

        $moduleNames = $modules ?? array_keys(app(ModuleManager::class)->discover());

        $this->withTenant($path, function () use ($owner, $admins, $moduleNames): void {
            Artisan::call('migrate', ['--force' => true]);

            $manager = app(ModuleManager::class);
            foreach ($moduleNames as $module) {
                $manager->install($module);
            }

            (new AuthSeeder())->run();
            $this->seedAdmins($owner, $admins);
            (new SettingSeeder())->run();
        });

        return Workspace::query()->create([
            'name' => $name,
            'slug' => $slug,
            'database' => $filename,
            'is_main' => false,
            'owner_user_id' => $owner->getKey(),
        ]);
    }

    /**
     * Move a workspace to trash (soft delete). The SQLite file is KEPT so the
     * database can be restored within the retention window. Main is never
     * deletable.
     */
    public function trash(Workspace $workspace): void
    {
        if ($workspace->is_main) {
            return;
        }

        $workspace->delete(); // soft delete — file retained for restore
    }

    /** Bring a trashed workspace back. */
    public function restore(Workspace $workspace): void
    {
        $workspace->restore();
    }

    /**
     * Permanently delete a workspace and its SQLite file (no restore). Main is
     * never deletable. Used by the retention sweep and any "delete forever".
     */
    public function delete(Workspace $workspace): void
    {
        if ($workspace->is_main) {
            return;
        }

        $path = $workspace->databasePath();
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }

        $workspace->forceDelete();
    }

    /**
     * Purge every trashed workspace whose retention window has elapsed (file +
     * row gone for good). Returns the number purged. Driven by a daily schedule.
     */
    public function purgeExpired(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $count = 0;

        foreach (Workspace::onlyTrashed()->where('deleted_at', '<=', $cutoff)->get() as $workspace) {
            $this->delete($workspace);
            $count++;
        }

        return $count;
    }

    /**
     * Copy the Main admins (and the creating owner) into the active tenant DB,
     * matched by email. Password hashes are copied verbatim — the `hashed`
     * cast passes already-hashed values through, so logins keep working.
     *
     * @param  list<array{name: string, email: string|null, password: string}>  $admins
     */
    private function seedAdmins(User $owner, array $admins): void
    {
        $admins[] = [
            'name' => (string) $owner->name,
            'email' => $owner->email,
            'password' => (string) $owner->password,
        ];

        foreach ($admins as $admin) {
            if ($admin['email'] === null || $admin['email'] === '') {
                continue;
            }

            User::query()->updateOrCreate(
                ['email' => $admin['email']],
                ['name' => $admin['name'], 'is_admin' => true, 'password' => $admin['password']],
            );
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) !== '' ? Str::slug($name) : 'workspace';
        $slug = $base;
        $n = 1;

        while (Workspace::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }
}

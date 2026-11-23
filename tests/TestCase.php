<?php

namespace Tests;

use App\Models\Auth\ModelAccess;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateParallelStorage();
    }

    /**
     * Under `artisan test --parallel` every process gets its own storage
     * folder. The in-memory databases are already separate, but files are
     * not: workspace SQLite files, backups and faked disks would be shared,
     * and several tests clean up by deleting every workspace file they find —
     * which, run side by side, deletes another process's database mid-test.
     * A no-op when the suite runs one test at a time.
     */
    private function isolateParallelStorage(): void
    {
        $token = ParallelTesting::token();
        if ($token === false) {
            return;
        }

        $root = base_path('storage/framework/testing/parallel-' . $token);
        if (! is_dir($root . '/app')) {
            mkdir($root . '/app', 0775, true);
        }

        // storage_path() callers (workspace files, Storage::fake) follow this…
        $this->app->useStoragePath($root);
        // …but disk roots were read from config at boot, so move them too.
        config([
            'filesystems.disks.local.root' => $root . '/app/private',
            'filesystems.disks.public.root' => $root . '/app/public',
        ]);
        $this->app->forgetInstance('filesystem');
        \Illuminate\Support\Facades\Storage::clearResolvedInstances();
    }

    /**
     * Grant every user full operate access (read/write/create, no delete) on
     * the given `ir_model` keys, via a global rule.
     *
     * Bespoke module screens are ACL-gated like the engine ones, and access is
     * deny-by-default — so a bare `User::factory()->create()` can't open them.
     * Tests whose subject is a ROLE rule (accountant, manager) rather than the
     * ACL itself use this to put the user in the same position a staff account
     * with that app granted would be in.
     */
    protected function grantEveryone(string ...$models): void
    {
        foreach ($models as $model) {
            ModelAccess::query()->firstOrCreate(
                ['model' => $model, 'group_id' => null],
                [
                    'name' => 'test:' . $model,
                    'perm_read' => true,
                    'perm_write' => true,
                    'perm_create' => true,
                    'perm_unlink' => false,
                ],
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * The CLI twin of Settings → Users' Delete action, for a GLOBAL account
 * (shared across every database) the Users tab can only ever delete on Main —
 * never from inside a workspace, since its identity belongs to Main.
 */
final class RemoveUserCommandTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        $dir = storage_path('app/workspaces');
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.sqlite') ?: [] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_it_removes_the_account_on_main(): void
    {
        User::factory()->create(['email' => 'demo@example.com', 'is_admin' => true]);

        $this->artisan('user:remove', ['email' => 'demo@example.com'])->assertSuccessful();

        $this->assertNull(User::query()->where('email', 'demo@example.com')->first());
    }

    public function test_it_no_ops_when_nothing_matches(): void
    {
        $this->artisan('user:remove', ['email' => 'ghost@example.com'])
            ->assertSuccessful()
            ->expectsOutputToContain('No account found');
    }

    public function test_it_removes_a_shared_account_from_every_workspace_too(): void
    {
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true, 'email' => 'owner@erp.test']);
        User::factory()->create(['email' => 'demo@example.com', 'is_admin' => true]);

        $workspace = app(WorkspaceManager::class)->provision('Branch', $owner, ['pos']);

        // Provisioning copies every Main admin into the new tenant (seedAdmins),
        // so the demo account should already exist there too.
        $existsInTenant = app(WorkspaceManager::class)->withTenant(
            (string) $workspace->databasePath(),
            fn (): bool => User::query()->where('email', 'demo@example.com')->exists(),
        );
        $this->assertTrue($existsInTenant);

        $this->artisan('user:remove', ['email' => 'demo@example.com'])
            ->assertSuccessful();

        $this->assertNull(User::query()->where('email', 'demo@example.com')->first());

        $stillInTenant = app(WorkspaceManager::class)->withTenant(
            (string) $workspace->databasePath(),
            fn (): bool => User::query()->where('email', 'demo@example.com')->exists(),
        );
        $this->assertFalse($stillInTenant);
    }

    public function test_databases_option_restricts_to_the_given_workspace(): void
    {
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true, 'email' => 'owner2@erp.test']);
        User::factory()->create(['email' => 'demo2@example.com', 'is_admin' => true]);

        $workspace = app(WorkspaceManager::class)->provision('Branch Two', $owner, ['pos']);

        // Only the new workspace's id — Main is deliberately excluded.
        $this->artisan('user:remove', [
            'email' => 'demo2@example.com',
            '--databases' => (string) $workspace->id,
        ])->assertSuccessful();

        // Left alone on Main.
        $this->assertNotNull(User::query()->where('email', 'demo2@example.com')->first());

        $stillInTenant = app(WorkspaceManager::class)->withTenant(
            (string) $workspace->databasePath(),
            fn (): bool => User::query()->where('email', 'demo2@example.com')->exists(),
        );
        $this->assertFalse($stillInTenant);
    }

    public function test_it_requires_an_email(): void
    {
        $this->artisan('user:remove', ['email' => ''])->assertFailed();
    }
}

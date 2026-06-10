<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\WorkspacesPage;
use App\Models\Ir\IrModule;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Pos\Models\PosProduct;
use Tests\TestCase;

/**
 * Multi-database ("My database") manager: each workspace is a separate SQLite
 * file, fully provisioned, isolated from Main, and admin-managed.
 */
final class WorkspaceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'email' => 'owner@erp.test']));
    }

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

    public function test_main_workspace_is_the_default_with_no_cookie(): void
    {
        $manager = app(WorkspaceManager::class);
        $main = $manager->ensureMain();

        $this->assertTrue($main->is_main);
        $this->assertNull($main->database);
        $this->assertSame($main->id, $manager->current()->id);
    }

    public function test_provision_builds_an_isolated_database_with_modules_and_admin(): void
    {
        // Main has POS installed with one product.
        app(ModuleManager::class)->install('pos');
        PosProduct::query()->create(['name' => 'Main Coffee', 'price' => 2.0, 'tax_rate' => 0.0, 'active' => true]);

        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);

        $workspace = app(WorkspaceManager::class)->provision('Branch 2', $owner, ['pos']);

        $this->assertFalse($workspace->is_main);
        $path = $workspace->databasePath();
        $this->assertNotNull($path);
        $this->assertFileExists($path);

        // Inside the tenant DB: POS installed, NO products (isolated), owner admin present.
        app(WorkspaceManager::class)->withTenant($path, function () use ($owner): void {
            $this->assertTrue(Schema::hasTable('pos_products'));
            $this->assertSame(0, PosProduct::query()->count());
            $this->assertTrue(
                IrModule::query()->where('name', 'pos')->where('state', ModuleState::Installed)->exists(),
            );
            $this->assertTrue(
                User::query()->where('email', $owner->email)->where('is_admin', true)->exists(),
            );
        });

        // Main is untouched.
        $this->assertSame(1, PosProduct::query()->count());
    }

    public function test_delete_removes_the_file_and_the_row(): void
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);

        $workspace = app(WorkspaceManager::class)->provision('Scratch', $owner, ['pos']);
        $path = $workspace->databasePath();
        $this->assertFileExists($path);

        app(WorkspaceManager::class)->delete($workspace);

        $this->assertFileDoesNotExist($path);
        $this->assertNull(Workspace::query()->find($workspace->id));
    }

    public function test_main_workspace_cannot_be_deleted(): void
    {
        $manager = app(WorkspaceManager::class);
        $main = $manager->ensureMain();

        $manager->delete($main);

        $this->assertNotNull(Workspace::query()->find($main->id));
    }

    public function test_non_admin_cannot_open_the_manager(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(WorkspacesPage::class)->assertForbidden();
    }

    public function test_create_validates_the_name(): void
    {
        Livewire::test(WorkspacesPage::class)
            ->set('newName', '')
            ->call('create')
            ->assertHasErrors('newName');

        // Only Main exists (no workspace provisioned on a validation failure).
        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_cookie_routes_the_request_to_the_tenant_and_keeps_auth(): void
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);

        $workspace = app(WorkspaceManager::class)->provision('Tenant', $owner, ['pos']);

        // With the workspace cookie the request renders against the tenant DB
        // and STAYS authenticated (the owner is seeded into the tenant by
        // email and rebound by the middleware) — a redirect to /login would
        // mean the auth rebind failed.
        $this->withUnencryptedCookie(Workspace::COOKIE, (string) $workspace->id)
            ->get('/')
            ->assertOk();
    }

    public function test_switch_requires_admin_and_sets_the_cookie(): void
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);
        $workspace = app(WorkspaceManager::class)->provision('Branch', $owner, ['pos']);

        $this->get('/workspaces/switch/' . $workspace->id)
            ->assertRedirect('/')
            ->assertCookie(Workspace::COOKIE);

        // Non-admins are refused.
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/workspaces/switch/' . $workspace->id)->assertForbidden();
    }
}

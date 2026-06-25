<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Http\Middleware\SetActiveWorkspace;
use App\Livewire\WorkspacesPage;
use App\Models\Ir\IrModule;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Pos\Models\PosProduct;
use Symfony\Component\HttpFoundation\Response;
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
        // The owner is a super admin so these tests exercise the workspace CRUD
        // directly; the regular-admin email-OTP gate is covered by SuperAdminTest.
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true, 'email' => 'owner@erp.test']));
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

    public function test_workspaces_migrate_backfills_later_module_migrations(): void
    {
        app(ModuleManager::class)->install('pos');
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);
        $workspace = app(WorkspaceManager::class)->provision('Backfill', $owner, ['pos']);
        $path = $workspace->databasePath();
        $this->assertNotNull($path);

        // Simulate a tenant provisioned BEFORE a recent POS migration: drop the
        // columns and forget the migration record so `migrate` re-runs it.
        app(WorkspaceManager::class)->withTenant($path, function (): void {
            Schema::table('pos_tables', function ($t): void {
                $t->dropColumn(['pos_x', 'pos_y']);
            });
            DB::table('migrations')->where('migration', 'like', '%add_position_to_pos_tables')->delete();
            $this->assertFalse(Schema::hasColumn('pos_tables', 'pos_x'));
        });

        // The deploy's per-tenant step backfills installed-module migrations.
        Artisan::call('workspaces:migrate');

        app(WorkspaceManager::class)->withTenant($path, function (): void {
            $this->assertTrue(Schema::hasColumn('pos_tables', 'pos_x'));
        });
    }

    public function test_install_modules_backfills_a_later_module_into_existing_tenants(): void
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);

        // A tenant provisioned with only POS — as if Limousine didn't exist yet.
        $workspace = app(WorkspaceManager::class)->provision('Old branch', $owner, ['pos']);
        $path = $workspace->databasePath();
        $this->assertNotNull($path);

        // Limousine is NOT in this tenant's registry → app bar can't show it.
        app(WorkspaceManager::class)->withTenant($path, function (): void {
            $this->assertFalse(
                IrModule::query()->where('name', 'limousine')->where('state', ModuleState::Installed)->exists(),
            );
        });

        // The deploy's backfill step installs every discovered module everywhere.
        Artisan::call('workspaces:install-modules');

        app(WorkspaceManager::class)->withTenant($path, function (): void {
            $this->assertTrue(
                IrModule::query()->where('name', 'limousine')->where('state', ModuleState::Installed)->exists(),
            );
            $this->assertTrue(
                IrModule::query()->where('name', 'rental')->where('state', ModuleState::Installed)->exists(),
            );
        });
    }

    public function test_install_modules_can_target_a_single_module(): void
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);
        $workspace = app(WorkspaceManager::class)->provision('Targeted', $owner, ['pos']);
        $path = $workspace->databasePath();
        $this->assertNotNull($path);

        // Use a standalone app (Project depends only on base) so "single
        // target" really means one module. Limousine now depends on Rental, so
        // it would pull a second app in — not what this test is checking.
        Artisan::call('workspaces:install-modules', ['name' => 'project']);

        app(WorkspaceManager::class)->withTenant($path, function (): void {
            $this->assertTrue(
                IrModule::query()->where('name', 'project')->where('state', ModuleState::Installed)->exists(),
            );
            // Rental was not requested → still absent.
            $this->assertFalse(
                IrModule::query()->where('name', 'rental')->where('state', ModuleState::Installed)->exists(),
            );
        });
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

    public function test_rename_updates_a_workspace_name(): void
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);
        $workspace = app(WorkspaceManager::class)->provision('Old name', $owner, ['pos']);

        Livewire::test(WorkspacesPage::class)
            ->call('startRename', $workspace->id)
            ->assertSet('editName', 'Old name')
            ->set('editName', 'New name')
            ->call('rename')
            ->assertSet('editingId', null);

        $this->assertSame('New name', Workspace::query()->find($workspace->id)?->name);
    }

    public function test_rename_requires_a_name(): void
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);
        $workspace = app(WorkspaceManager::class)->provision('Keep', $owner, ['pos']);

        Livewire::test(WorkspacesPage::class)
            ->call('startRename', $workspace->id)
            ->set('editName', '')
            ->call('rename')
            ->assertHasErrors(['editName' => 'required']);

        $this->assertSame('Keep', Workspace::query()->find($workspace->id)?->name);
    }

    public function test_delete_requires_the_password_then_moves_to_trash(): void
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);
        $workspace = app(WorkspaceManager::class)->provision('Trash me', $owner, ['pos']);
        $path = $workspace->databasePath();

        // Wrong password → error, still active.
        Livewire::test(WorkspacesPage::class)
            ->call('confirmDelete', $workspace->id)
            ->set('deletePassword', 'not-it')
            ->call('deleteWorkspace')
            ->assertHasErrors('deletePassword');
        $this->assertFalse(Workspace::withTrashed()->find($workspace->id)?->trashed());

        // Correct password (factory default 'password') → trashed, file KEPT.
        Livewire::test(WorkspacesPage::class)
            ->call('confirmDelete', $workspace->id)
            ->set('deletePassword', 'password')
            ->call('deleteWorkspace')
            ->assertHasNoErrors();

        $this->assertTrue(Workspace::withTrashed()->find($workspace->id)?->trashed());
        $this->assertNull(app(WorkspaceManager::class)->find($workspace->id)); // not switchable
        $this->assertFileExists($path); // restorable — file retained
    }

    public function test_restore_brings_a_trashed_workspace_back(): void
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);
        $workspace = app(WorkspaceManager::class)->provision('Bring back', $owner, ['pos']);
        app(WorkspaceManager::class)->trash($workspace);

        Livewire::test(WorkspacesPage::class)->call('restoreWorkspace', $workspace->id);

        $this->assertFalse(Workspace::withTrashed()->find($workspace->id)?->trashed());
        $this->assertNotNull(app(WorkspaceManager::class)->find($workspace->id));
    }

    public function test_purge_permanently_deletes_after_the_retention_window(): void
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);
        $workspace = app(WorkspaceManager::class)->provision('Old trash', $owner, ['pos']);
        $path = $workspace->databasePath();
        app(WorkspaceManager::class)->trash($workspace);

        // Age the deletion just past the retention window.
        Workspace::withTrashed()->where('id', $workspace->id)
            ->update(['deleted_at' => now()->subDays(WorkspaceManager::RETENTION_DAYS + 1)]);

        $purged = app(WorkspaceManager::class)->purgeExpired();

        $this->assertSame(1, $purged);
        $this->assertNull(Workspace::withTrashed()->find($workspace->id)); // gone for good
        $this->assertFileDoesNotExist($path);
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

    public function test_guest_request_with_a_stale_cookie_stays_on_main(): void
    {
        // Regression: a not-yet-authenticated request carrying a leftover
        // workspace cookie must NOT swap to the tenant DB. Otherwise the login
        // form authenticates against the tenant and persists a tenant-local id
        // that, read back on Main, belongs to a different person ("log in as
        // qassim, land as ramadan"). Guests always operate on Main.
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);
        $workspace = app(WorkspaceManager::class)->provision('Tenant', $owner, ['pos']);

        Auth::logout();
        $this->assertFalse(Auth::check());

        $default = DB::getDefaultConnection();

        $request = Request::create('/login', 'GET');
        $request->cookies->set(Workspace::COOKIE, (string) $workspace->id);

        $captured = null;
        app(SetActiveWorkspace::class)->handle($request, function () use (&$captured): Response {
            $captured = DB::getDefaultConnection();

            return new Response('ok');
        });

        // The tenant was never activated — the request ran on the default
        // (Main) connection, so login authenticates against Main.
        $this->assertSame($default, $captured);
        $this->assertNotSame('tenant', $captured);
    }

    public function test_authenticated_request_is_rebound_to_the_tenant_account_by_email(): void
    {
        // The happy path still works: a signed-in admin with the cookie is
        // routed to the tenant DB and rebound to the matching tenant account.
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);
        $workspace = app(WorkspaceManager::class)->provision('Tenant', $owner, ['pos']);

        $request = Request::create('/', 'GET');
        $request->cookies->set(Workspace::COOKIE, (string) $workspace->id);

        $mainConnection = DB::getDefaultConnection();

        $capturedConnection = null;
        $capturedEmail = null;
        app(SetActiveWorkspace::class)->handle($request, function () use (&$capturedConnection, &$capturedEmail): Response {
            $capturedConnection = DB::getDefaultConnection();
            $capturedEmail = Auth::user()?->email;

            return new Response('ok');
        });

        $this->assertSame('tenant', $capturedConnection);
        $this->assertSame($owner->email, $capturedEmail);

        // Restore the default connection: a real request ends after the swap,
        // but here the test process continues into tearDown (which deletes the
        // tenant file, then rolls back migrations on the default connection).
        config(['database.default' => $mainConnection]);
        DB::setDefaultConnection($mainConnection);
        DB::purge('tenant');
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

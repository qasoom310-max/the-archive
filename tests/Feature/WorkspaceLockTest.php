<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Admin\UserProvisioner;
use App\Erp\Tenancy\WorkspaceManager;
use App\Http\Middleware\SetActiveWorkspace;
use App\Livewire\Settings\UserManager;
use App\Livewire\WorkspacesPage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * A user can be LOCKED to a single workspace: full owner inside that database,
 * with a bare login shell on Main, and no way to reach — or even see — any
 * other database. The tenancy layer forces them into their workspace on every
 * request; switching and the database manager are refused.
 */
final class WorkspaceLockTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create([
            'is_admin' => true, 'is_super_admin' => true, 'email' => 'owner@erp.test',
        ]));
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

    private function kaleem(): Workspace
    {
        $owner = Auth::user();
        $this->assertInstanceOf(User::class, $owner);

        return app(WorkspaceManager::class)->provision('Kaleem', $owner, ['contacts']);
    }

    public function test_provision_locked_makes_a_shell_on_main_and_a_super_admin_in_the_tenant(): void
    {
        $ws = $this->kaleem();

        $shell = app(UserProvisioner::class)->provisionLocked('K Owner', 'kadmin@erp.test', 'password123', $ws->id, true);

        // Main: a non-admin login shell, locked to Kaleem.
        $this->assertNotNull($shell);
        $this->assertFalse((bool) $shell->is_admin);
        $this->assertFalse((bool) $shell->is_super_admin);
        $this->assertSame($ws->id, $shell->homeWorkspaceId());
        $this->assertTrue($shell->isLockedToWorkspace());

        // Kaleem tenant DB: the real account is a full owner, also flagged locked.
        app(WorkspaceManager::class)->withTenant((string) $ws->databasePath(), function () use ($ws): void {
            $tenant = User::query()->where('email', 'kadmin@erp.test')->firstOrFail();
            $this->assertTrue((bool) $tenant->is_admin);
            $this->assertTrue((bool) $tenant->is_super_admin);
            $this->assertSame($ws->id, $tenant->homeWorkspaceId());
        });
    }

    public function test_a_locked_user_is_forced_into_their_workspace_ignoring_the_cookie(): void
    {
        $ws = $this->kaleem();
        $shell = app(UserProvisioner::class)->provisionLocked('K Owner', 'kadmin@erp.test', 'password123', $ws->id, true);
        $this->assertNotNull($shell);
        $this->actingAs($shell);

        $mainConnection = DB::getDefaultConnection();

        // No cookie at all — yet they must land in Kaleem, as its super admin.
        $request = Request::create('/', 'GET');
        $connection = null;
        $email = null;
        $super = null;
        app(SetActiveWorkspace::class)->handle($request, function () use (&$connection, &$email, &$super): Response {
            $connection = DB::getDefaultConnection();
            $email = Auth::user()?->email;
            $super = Auth::user()?->isSuperAdmin();

            return new Response('ok');
        });

        $this->assertSame('tenant', $connection);      // forced into Kaleem
        $this->assertSame('kadmin@erp.test', $email);  // rebound to the tenant account
        $this->assertTrue($super);                     // which is a super admin there

        // Restore for tearDown (a real request ends after the swap).
        config(['database.default' => $mainConnection]);
        DB::setDefaultConnection($mainConnection);
        DB::purge('tenant');
    }

    public function test_a_locked_user_cannot_switch_workspaces(): void
    {
        // Locked, but is_admin on Main — isolates the lock guard (not the admin one).
        $locked = User::factory()->create(['is_admin' => true, 'home_workspace_id' => 999]);
        $this->actingAs($locked);

        $this->get('/workspaces/switch/1')->assertForbidden();
    }

    public function test_a_locked_user_cannot_open_the_database_manager(): void
    {
        $locked = User::factory()->create(['is_admin' => true, 'is_super_admin' => true, 'home_workspace_id' => 999]);

        Livewire::actingAs($locked)->test(WorkspacesPage::class)->assertForbidden();
    }

    public function test_the_user_form_creates_a_kaleem_locked_super_admin(): void
    {
        $ws = $this->kaleem();

        Livewire::test(UserManager::class)
            ->set('name', 'Kaleem Manager')
            ->set('email', 'kmgr@erp.test')
            ->set('password', 'password123')
            ->set('lockToWorkspace', true)
            ->set('lockWorkspaceId', $ws->id)
            ->call('save')
            ->assertHasNoErrors();

        $shell = User::query()->where('email', 'kmgr@erp.test')->firstOrFail();
        $this->assertFalse((bool) $shell->is_admin);          // Main shell only
        $this->assertSame($ws->id, $shell->homeWorkspaceId()); // locked to Kaleem

        app(WorkspaceManager::class)->withTenant((string) $ws->databasePath(), function (): void {
            $this->assertTrue(
                User::query()->where('email', 'kmgr@erp.test')->where('is_super_admin', true)->exists(),
            );
        });
    }

    public function test_backfill_gives_a_workspace_only_user_a_main_login(): void
    {
        $ws = $this->kaleem();

        // A user created ONLY inside Kaleem (not Main) — the footgun.
        app(WorkspaceManager::class)->withTenant((string) $ws->databasePath(), static function (): void {
            User::query()->create([
                'name' => 'Hussain', 'email' => 'hussain@abc.test',
                'password' => 'secret12345', 'is_admin' => true, 'is_super_admin' => true,
            ]);
        });
        $this->assertNull(User::query()->where('email', 'hussain@abc.test')->first());

        $this->artisan('users:backfill-logins --apply')->assertSuccessful();

        $main = User::query()->where('email', 'hussain@abc.test')->first();
        $this->assertNotNull($main);
        $this->assertSame($ws->id, $main->homeWorkspaceId());   // locked to Kaleem
        $this->assertFalse((bool) $main->is_admin);             // Main is only a login shell
        // Their existing password still works (hash copied verbatim, not re-hashed).
        $this->assertTrue(Hash::check('secret12345', (string) $main->password));
    }

    public function test_a_regular_admin_cannot_create_a_locked_admin(): void
    {
        $ws = $this->kaleem();

        // A plain admin (not super) may open the Users screen but not mint a
        // workspace-locked owner.
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));

        Livewire::test(UserManager::class)
            ->set('name', 'Sneaky')
            ->set('email', 'sneaky@erp.test')
            ->set('password', 'password123')
            ->set('lockToWorkspace', true)
            ->set('lockWorkspaceId', $ws->id)
            ->call('save')
            ->assertHasErrors('lockToWorkspace');

        $this->assertSame(0, User::query()->where('email', 'sneaky@erp.test')->count());
    }
}

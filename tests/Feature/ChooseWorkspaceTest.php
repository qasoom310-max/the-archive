<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Admin\StaffRole;
use App\Erp\Admin\UserProvisioner;
use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\Auth\Login;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * After signing in, a user PICKS which business database to enter instead of
 * being dropped into whichever one a stale year-long cookie remembered. The
 * picker is post-authentication (never on the public login form), and users
 * with only one place to go — locked staff, or a single-database account — skip
 * straight in.
 */
final class ChooseWorkspaceTest extends TestCase
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

    private function owner(): User
    {
        return User::factory()->create([
            'is_admin' => true, 'is_super_admin' => true, 'email' => 'owner@erp.test',
        ]);
    }

    /** Owner (Main admin) with two provisioned tenants → three accessible databases. */
    private function ownerWithTwoTenants(): User
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $manager = app(WorkspaceManager::class);
        $manager->provision('Kaleem', $owner, ['contacts']);
        $manager->provision('Sweileh Cafe', $owner, ['contacts']);

        return $owner;
    }

    public function test_login_lands_on_the_chooser(): void
    {
        $owner = $this->owner();

        Livewire::test(Login::class)
            ->set('email', 'owner@erp.test')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('workspaces.choose'));

        $this->assertTrue(Auth::check());
    }

    public function test_the_chooser_lists_every_accessible_database(): void
    {
        $owner = $this->ownerWithTwoTenants();

        $this->actingAs($owner)
            ->get(route('workspaces.choose'))
            ->assertOk()
            ->assertSee('Choose a database')
            ->assertSee('Main')
            ->assertSee('Kaleem')
            ->assertSee('Sweileh Cafe');
    }

    public function test_entering_a_database_sets_the_cookie_and_goes_home(): void
    {
        $owner = $this->ownerWithTwoTenants();
        $kaleem = app(WorkspaceManager::class)->all()->firstWhere('name', 'Kaleem');
        $this->assertInstanceOf(Workspace::class, $kaleem);

        $this->actingAs($owner)
            ->get(route('workspaces.enter', $kaleem->id))
            ->assertRedirect('/')
            ->assertCookie(Workspace::COOKIE, (string) $kaleem->id);
    }

    public function test_a_single_database_user_skips_the_chooser(): void
    {
        // A plain Main user (no tenants) has only Main → straight in, cookie set.
        $solo = User::factory()->create(['is_admin' => true, 'email' => 'solo@erp.test']);
        app(WorkspaceManager::class)->ensureMain();

        $main = app(WorkspaceManager::class)->all()->firstWhere('is_main', true);
        $this->assertInstanceOf(Workspace::class, $main);

        $this->actingAs($solo)
            ->get(route('workspaces.choose'))
            ->assertRedirect('/')
            ->assertCookie(Workspace::COOKIE, (string) $main->id);
    }

    public function test_a_locked_user_skips_straight_into_their_home_database(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $ws = app(WorkspaceManager::class)->provision('Kaleem', $owner, ['contacts']);

        $shell = app(UserProvisioner::class)->provisionLocked('K Owner', 'kadmin@erp.test', 'password123', $ws->id, StaffRole::SuperAdmin);
        $this->assertNotNull($shell);

        $this->actingAs($shell)
            ->get(route('workspaces.choose'))
            ->assertRedirect('/')
            ->assertCookie(Workspace::COOKIE, (string) $ws->id);
    }

    public function test_a_user_cannot_enter_a_database_they_have_no_account_in(): void
    {
        // Kaleem provisioned by the owner; a DIFFERENT user has no account there.
        $owner = $this->owner();
        $this->actingAs($owner);
        $ws = app(WorkspaceManager::class)->provision('Kaleem', $owner, ['contacts']);

        $stranger = User::factory()->create(['is_admin' => false, 'email' => 'stranger@erp.test']);

        $this->actingAs($stranger)
            ->get(route('workspaces.enter', $ws->id))
            ->assertForbidden();
    }

    public function test_guests_are_bounced_to_login(): void
    {
        $this->get(route('workspaces.choose'))->assertRedirect(route('login'));
    }
}

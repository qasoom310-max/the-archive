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

    /**
     * An invoice link opened while signed out (a phone tapping it in WhatsApp)
     * lands on that invoice after signing in, not on the home page.
     */
    public function test_a_link_opened_while_signed_out_is_where_sign_in_lands(): void
    {
        $solo = User::factory()->create(['is_admin' => true, 'email' => 'solo@erp.test']);
        app(WorkspaceManager::class)->ensureMain();

        $this->get('/profile?from=whatsapp')->assertRedirect(route('login'));

        $this->actingAs($solo)
            ->get(route('workspaces.choose'))
            ->assertRedirect(url('/profile?from=whatsapp'));
    }

    /** With a choice of databases, the link is kept until one is picked. */
    public function test_the_link_survives_the_database_picker(): void
    {
        $owner = $this->ownerWithTwoTenants();
        $kaleem = app(WorkspaceManager::class)->all()->firstWhere('name', 'Kaleem');
        $this->assertInstanceOf(Workspace::class, $kaleem);

        Auth::logout();
        $this->get('/profile?from=whatsapp')->assertRedirect(route('login'));

        $this->actingAs($owner)->get(route('workspaces.choose'))->assertOk();
        $this->actingAs($owner)
            ->get(route('workspaces.enter', $kaleem->id))
            ->assertRedirect(url('/profile?from=whatsapp'));

        // Pulled once: switching database later goes home again.
        $this->actingAs($owner)
            ->get(route('workspaces.enter', $kaleem->id))
            ->assertRedirect('/');
    }

    /**
     * Staff of one business whose Main account is only a login: a browser
     * without the workspace cookie used to leave them on Main, where every
     * page is 403 (Hasan, 2026-10-27). They are sent to their own database.
     */
    public function test_a_staff_member_with_no_cookie_lands_in_their_own_database(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $wanaan = app(WorkspaceManager::class)->provision('Wanaan', $owner, ['contacts']);

        $hasan = app(UserProvisioner::class)->provision('Hasan', 'hasan@erp.test', 'password123', ['contacts'], [(int) $wanaan->id]);

        $this->actingAs($hasan)
            ->get('/profile')
            ->assertOk()
            ->assertCookie(Workspace::COOKIE, (string) $wanaan->id);

        $this->assertSame('tenant', Auth::user()?->getConnectionName());
    }

    /** A cookie for a database he has no account in no longer strands him on Main. */
    public function test_another_businesss_cookie_does_not_strand_staff_on_main(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $wanaan = app(WorkspaceManager::class)->provision('Wanaan', $owner, ['contacts']);
        $kaleem = app(WorkspaceManager::class)->provision('Kaleem', $owner, ['contacts']);

        $hasan = app(UserProvisioner::class)->provision('Hasan', 'hasan@erp.test', 'password123', ['contacts'], [(int) $wanaan->id]);

        $this->actingAs($hasan)
            ->withUnencryptedCookie(Workspace::COOKIE, (string) $kaleem->id)
            ->get('/profile')
            ->assertOk()
            ->assertCookie(Workspace::COOKIE, (string) $wanaan->id);
    }

    /** Someone who works on Main stays there with no cookie, as before. */
    public function test_an_admin_with_no_cookie_stays_on_main(): void
    {
        $owner = $this->ownerWithTwoTenants();

        $this->actingAs($owner)
            ->get('/profile')
            ->assertOk()
            ->assertCookieMissing(Workspace::COOKIE);
    }
}

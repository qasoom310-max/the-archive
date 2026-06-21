<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\Settings\UserManager;
use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin-only "Users" tab in Settings: create a staff account with
 * view-only access to chosen apps + databases.
 */
final class UserManagerTest extends TestCase
{
    use DatabaseMigrations;

    private function installPos(): void
    {
        // Installing pos pulls contacts too, so both registries exist.
        app(ModuleManager::class)->install('pos');
    }

    public function test_admin_creates_a_view_only_user_for_the_selected_apps(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->installPos();

        Livewire::test(UserManager::class)
            ->set('name', 'Cashier One')
            ->set('email', 'cashier1@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos'])
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'cashier1@example.com')->firstOrFail();
        $this->assertFalse($user->isAdmin());

        // A dedicated per-user group carries the grants.
        $group = Group::query()->where('code', 'user:' . $user->getKey())->firstOrFail();
        $this->assertTrue($user->groups()->whereKey($group->id)->exists());

        // View only — Read on the granted app's models, nothing else.
        $access = app(AccessControl::class);
        $this->assertTrue($access->allows($user, 'pos.order', Permission::Read));
        $this->assertFalse($access->allows($user, 'pos.order', Permission::Write));
        $this->assertFalse($access->allows($user, 'pos.order', Permission::Create));
        $this->assertFalse($access->allows($user, 'pos.order', Permission::Unlink));

        // Every grant on the group is read-only.
        $this->assertSame(0, ModelAccess::query()->where('group_id', $group->id)
            ->where(function ($q): void {
                $q->where('perm_write', true)->orWhere('perm_create', true)->orWhere('perm_unlink', true);
            })->count());
    }

    public function test_user_only_gets_access_to_the_granted_apps(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->installPos();

        Livewire::test(UserManager::class)
            ->set('name', 'Pos Only')
            ->set('email', 'posonly@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos'])
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'posonly@example.com')->firstOrFail();
        $access = app(AccessControl::class);

        $this->assertTrue($access->allows($user, 'pos.order', Permission::Read));
        // contacts was installed as a pos dependency but NOT granted.
        $this->assertFalse($access->allows($user, 'contacts.partner', Permission::Read));
    }

    public function test_non_admin_cannot_use_the_manager(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(UserManager::class)->assertForbidden();
    }

    public function test_validation_requires_name_email_and_password(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(UserManager::class)
            ->set('name', '')
            ->set('email', 'not-an-email')
            ->set('password', 'short')
            ->call('createUser')
            ->assertHasErrors(['name' => 'required', 'email' => 'email', 'password' => 'min']);

        $this->assertSame(0, User::query()->where('is_admin', false)->count());
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(UserManager::class)
            ->set('name', 'Dupe')
            ->set('email', 'taken@example.com')
            ->set('password', 'secret12')
            ->call('createUser')
            ->assertHasErrors(['email']);
    }

    public function test_delete_removes_the_staff_user_and_their_group(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->installPos();

        Livewire::test(UserManager::class)
            ->set('name', 'Temp Staff')
            ->set('email', 'temp@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos'])
            ->call('createUser');

        $user = User::query()->where('email', 'temp@example.com')->firstOrFail();
        $groupId = (int) Group::query()->where('code', 'user:' . $user->getKey())->value('id');

        Livewire::test(UserManager::class)->call('deleteUser', $user->getKey());

        $this->assertNull(User::query()->find($user->getKey()));
        $this->assertNull(Group::query()->find($groupId));
        $this->assertSame(0, ModelAccess::query()->where('group_id', $groupId)->count());
    }

    public function test_admins_are_never_deletable_from_here(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $victim = User::factory()->create(['is_admin' => true]);

        Livewire::test(UserManager::class)->call('deleteUser', $victim->getKey());

        $this->assertNotNull(User::query()->find($victim->getKey()));
    }

    public function test_user_is_also_provisioned_into_a_selected_database(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@kaleem.test']);
        $this->actingAs($admin);
        $this->installPos();

        // A second database with only the apps we need (fast provisioning).
        $workspace = app(WorkspaceManager::class)->provision('Branch Two', $admin, ['contacts', 'pos']);

        Livewire::test(UserManager::class)
            ->set('name', 'Branch Cashier')
            ->set('email', 'branch@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos'])
            ->set('workspaces', [$workspace->id])
            ->call('createUser')
            ->assertHasNoErrors();

        // The account exists in the tenant DB with the same view-only grant.
        app(WorkspaceManager::class)->withTenant((string) $workspace->databasePath(), function (): void {
            $tenantUser = User::query()->where('email', 'branch@example.com')->first();
            $this->assertNotNull($tenantUser);
            $this->assertFalse($tenantUser->isAdmin());
            $this->assertTrue(app(AccessControl::class)->allows($tenantUser, 'pos.order', Permission::Read));
            $this->assertFalse(app(AccessControl::class)->allows($tenantUser, 'pos.order', Permission::Write));
        });
    }
}

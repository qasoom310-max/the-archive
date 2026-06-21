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
 * The admin-only "Users" tab in Settings: create / edit / delete a user with
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

    private function mainId(): int
    {
        return (int) app(WorkspaceManager::class)->ensureMain()->id;
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
            ->set('workspaces', [$this->mainId()])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'cashier1@example.com')->firstOrFail();
        $this->assertFalse($user->isAdmin());

        $group = Group::query()->where('code', 'user:' . $user->getKey())->firstOrFail();
        $this->assertTrue($user->groups()->whereKey($group->id)->exists());

        $access = app(AccessControl::class);
        $this->assertTrue($access->allows($user, 'pos.order', Permission::Read));
        $this->assertFalse($access->allows($user, 'pos.order', Permission::Write));
        $this->assertFalse($access->allows($user, 'pos.order', Permission::Create));
        $this->assertFalse($access->allows($user, 'pos.order', Permission::Unlink));

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
            ->set('workspaces', [$this->mainId()])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'posonly@example.com')->firstOrFail();
        $access = app(AccessControl::class);

        $this->assertTrue($access->allows($user, 'pos.order', Permission::Read));
        $this->assertFalse($access->allows($user, 'contacts.partner', Permission::Read));
    }

    public function test_create_requires_at_least_one_database(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(UserManager::class)
            ->set('name', 'No DB')
            ->set('email', 'nodb@example.com')
            ->set('password', 'secret12')
            ->set('workspaces', [])
            ->call('save')
            ->assertHasErrors(['workspaces']);

        $this->assertSame(0, User::query()->where('email', 'nodb@example.com')->count());
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
            ->set('workspaces', [$this->mainId()])
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'email' => 'email', 'password' => 'min']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(UserManager::class)
            ->set('name', 'Dupe')
            ->set('email', 'taken@example.com')
            ->set('password', 'secret12')
            ->set('workspaces', [$this->mainId()])
            ->call('save')
            ->assertHasErrors(['email']);
    }

    public function test_edit_updates_name_and_rewrites_app_grants(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->installPos();

        Livewire::test(UserManager::class)
            ->set('name', 'Editable')
            ->set('email', 'edit@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos'])
            ->set('workspaces', [$this->mainId()])
            ->call('save');

        $user = User::query()->where('email', 'edit@example.com')->firstOrFail();
        $access = app(AccessControl::class);
        $this->assertTrue($access->allows($user, 'pos.order', Permission::Read));

        Livewire::test(UserManager::class)
            ->call('editUser', $user->getKey())
            ->assertSet('editingId', $user->getKey())
            ->assertSet('name', 'Editable')
            ->assertSet('apps', ['pos'])
            ->set('name', 'Renamed')
            ->set('apps', []) // drop POS access
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editingId', null);

        $user->refresh();
        $this->assertSame('Renamed', $user->name);
        $this->assertFalse($access->allows($user->fresh(), 'pos.order', Permission::Read));
    }

    public function test_edit_keeps_password_when_left_blank(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(UserManager::class)
            ->set('name', 'Keeper')
            ->set('email', 'keep@example.com')
            ->set('password', 'secret12')
            ->set('workspaces', [$this->mainId()])
            ->call('save');

        $user = User::query()->where('email', 'keep@example.com')->firstOrFail();
        $originalHash = (string) $user->password;

        Livewire::test(UserManager::class)
            ->call('editUser', $user->getKey())
            ->set('name', 'Keeper Two')
            ->set('password', '') // blank → keep
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($originalHash, (string) $user->fresh()?->password);
    }

    public function test_can_delete_a_non_last_admin_but_not_yourself(): void
    {
        $me = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create(['is_admin' => true]);
        $this->actingAs($me);

        // Deleting myself is blocked.
        Livewire::test(UserManager::class)->call('deleteUser', $me->getKey());
        $this->assertNotNull(User::query()->find($me->getKey()));

        // Deleting another admin (not the last) is allowed.
        Livewire::test(UserManager::class)->call('deleteUser', $other->getKey());
        $this->assertNull(User::query()->find($other->getKey()));
    }

    public function test_delete_removes_a_staff_user_and_their_group(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->installPos();

        Livewire::test(UserManager::class)
            ->set('name', 'Temp Staff')
            ->set('email', 'temp@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos'])
            ->set('workspaces', [$this->mainId()])
            ->call('save');

        $user = User::query()->where('email', 'temp@example.com')->firstOrFail();
        $groupId = (int) Group::query()->where('code', 'user:' . $user->getKey())->value('id');

        Livewire::test(UserManager::class)->call('deleteUser', $user->getKey());

        $this->assertNull(User::query()->find($user->getKey()));
        $this->assertNull(Group::query()->find($groupId));
        $this->assertSame(0, ModelAccess::query()->where('group_id', $groupId)->count());
    }

    public function test_user_is_provisioned_only_into_the_selected_databases(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@kaleem.test']);
        $this->actingAs($admin);
        $this->installPos();

        $workspace = app(WorkspaceManager::class)->provision('Branch Two', $admin, ['contacts', 'pos']);

        // Tenant ONLY — Main is NOT selected, so the account lives only there.
        Livewire::test(UserManager::class)
            ->set('name', 'Branch Cashier')
            ->set('email', 'branch@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos'])
            ->set('workspaces', [$workspace->id])
            ->call('save')
            ->assertHasNoErrors();

        // Not created in Main.
        $this->assertSame(0, User::query()->where('email', 'branch@example.com')->count());

        // But present in the tenant DB with the view-only grant.
        app(WorkspaceManager::class)->withTenant((string) $workspace->databasePath(), function (): void {
            $tenantUser = User::query()->where('email', 'branch@example.com')->first();
            $this->assertNotNull($tenantUser);
            $this->assertFalse($tenantUser->isAdmin());
            $this->assertTrue(app(AccessControl::class)->allows($tenantUser, 'pos.order', Permission::Read));
            $this->assertFalse(app(AccessControl::class)->allows($tenantUser, 'pos.order', Permission::Write));
        });
    }
}

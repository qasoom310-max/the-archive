<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Admin\StaffRole;
use App\Erp\Modules\ModuleManager;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Settings\Setting;
use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\Settings\UserManager;
use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use App\Models\Workspace;
use Closure;
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
        // Super admin actor: editing is OTP-gated only for regular admins
        // (that path is covered by SuperAdminTest); here we test the edit logic.
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
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
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));

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
        $me = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
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
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
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

    public function test_admin_can_create_a_full_admin_user(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->installPos();

        Livewire::test(UserManager::class)
            ->set('name', 'New Admin')
            ->set('email', 'newadmin@example.com')
            ->set('password', 'secret12')
            ->set('role', 'admin')
            ->set('workspaces', [$this->mainId()])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'newadmin@example.com')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertFalse($user->isSuperAdmin());

        // An admin bypasses the ACL — no per-user access group is created.
        $this->assertNull(Group::query()->where('code', 'user:' . $user->getKey())->first());
    }

    public function test_edit_can_promote_a_staff_user_to_admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        $this->installPos();

        Livewire::test(UserManager::class)
            ->set('name', 'Promote Me')
            ->set('email', 'promote@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos'])
            ->set('workspaces', [$this->mainId()])
            ->call('save');

        $user = User::query()->where('email', 'promote@example.com')->firstOrFail();
        $this->assertFalse($user->isAdmin());

        Livewire::test(UserManager::class)
            ->call('editUser', $user->getKey())
            ->assertSet('role', 'staff')
            ->set('role', 'admin')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($user->fresh()?->isAdmin());
    }

    public function test_edit_loads_a_super_admins_real_role_and_can_change_it(): void
    {
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $target = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($owner);

        // The role picker now owns every tier — it loads the target's REAL role
        // and another super admin can change it.
        Livewire::test(UserManager::class)
            ->call('editUser', $target->getKey())
            ->assertSet('role', 'super')
            ->set('role', 'staff')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($target->fresh()?->isSuperAdmin());
        $this->assertFalse($target->fresh()?->isAdmin());
    }

    public function test_the_last_super_admin_cannot_be_demoted(): void
    {
        // Only ONE super admin exists — demoting them would lock the owner tier
        // out of the database, so the role change is refused (tier kept).
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($owner);

        // Someone else must be admin so `owner` isn't also the last admin.
        User::factory()->create(['is_admin' => true]);

        Livewire::test(UserManager::class)
            ->call('editUser', $owner->getKey())
            ->set('role', 'staff')
            ->call('save');

        $this->assertTrue($owner->fresh()?->isSuperAdmin());
    }

    public function test_a_supervisor_can_view_add_and_edit_but_not_delete(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->installPos();

        Livewire::test(UserManager::class)
            ->set('name', 'Shift Lead')
            ->set('email', 'lead@example.com')
            ->set('password', 'secret12')
            ->set('role', 'supervisor')
            ->set('apps', ['pos'])
            ->set('workspaces', [$this->mainId()])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'lead@example.com')->firstOrFail();
        $acl = app(AccessControl::class);

        $this->assertFalse($user->isAdmin());
        $this->assertTrue($acl->allows($user, 'pos.order', Permission::Read));
        $this->assertTrue($acl->allows($user, 'pos.order', Permission::Write));
        $this->assertTrue($acl->allows($user, 'pos.order', Permission::Create));
        $this->assertFalse($acl->allows($user, 'pos.order', Permission::Unlink), 'A supervisor must never be able to delete.');
    }

    public function test_editing_reads_back_the_supervisor_role_and_can_demote_to_staff(): void
    {
        // Super admin actor: editing is OTP-gated for a regular admin (covered
        // by SuperAdminTest); here we're testing the role logic itself.
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        $this->installPos();

        Livewire::test(UserManager::class)
            ->set('name', 'Shift Lead')
            ->set('email', 'lead@example.com')
            ->set('password', 'secret12')
            ->set('role', 'supervisor')
            ->set('apps', ['pos'])
            ->set('workspaces', [$this->mainId()])
            ->call('save');

        $user = User::query()->where('email', 'lead@example.com')->firstOrFail();

        // Supervisor isn't a column — it's the shape of the ACL rules. The edit
        // form must still read it back correctly.
        Livewire::test(UserManager::class)
            ->call('editUser', $user->getKey())
            ->assertSet('role', 'supervisor')
            ->set('role', 'staff')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(app(AccessControl::class)->allows($user->fresh(), 'pos.order', Permission::Write));
    }

    public function test_an_accountant_may_confirm_payments_and_is_not_an_admin(): void
    {
        // Owner-only to assign (it was an owner-only toggle before).
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        $this->installPos();

        Livewire::test(UserManager::class)
            ->set('name', 'Book Keeper')
            ->set('email', 'books@example.com')
            ->set('password', 'secret12')
            ->set('role', 'accountant')
            ->set('apps', ['pos'])
            ->set('workspaces', [$this->mainId()])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'books@example.com')->firstOrFail();

        $this->assertTrue($user->isAccountant());
        $this->assertTrue($user->canConfirmPayments());
        $this->assertFalse($user->isAdmin());
        // View-level access to the apps they were granted; no editing.
        $this->assertTrue(app(AccessControl::class)->allows($user, 'pos.order', Permission::Read));
        $this->assertFalse(app(AccessControl::class)->allows($user, 'pos.order', Permission::Write));
    }

    public function test_a_regular_admin_cannot_assign_the_owner_only_roles(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $this->installPos();

        foreach (['accountant', 'super'] as $role) {
            Livewire::test(UserManager::class)
                ->set('name', 'Sneaky ' . $role)
                ->set('email', $role . '@example.com')
                ->set('password', 'secret12')
                ->set('role', $role)
                ->set('workspaces', [$this->mainId()])
                ->call('save')
                ->assertHasErrors('role');

            $this->assertSame(0, User::query()->where('email', $role . '@example.com')->count());
        }
    }

    public function test_the_roles_a_super_admin_can_assign_include_every_tier(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));

        $roles = Livewire::test(UserManager::class)->viewData('roleOptions');
        $values = array_map(static fn (StaffRole $r): string => $r->value, $roles);

        $this->assertSame(['staff', 'supervisor', 'accountant', 'admin', 'super'], $values);

        // A regular admin sees only the three they may hand out.
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        $roles = Livewire::test(UserManager::class)->viewData('roleOptions');
        $values = array_map(static fn (StaffRole $r): string => $r->value, $roles);

        $this->assertSame(['staff', 'supervisor', 'admin'], $values);
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

    // ── The app list follows the database's business type ────────────────────

    public function test_the_app_checklist_only_offers_apps_this_business_type_runs(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        app(ModuleManager::class)->install('rental');

        // A café runs POS; it does NOT run Rent A Car.
        Setting::set('company.business_type', 'cafe');

        $apps = Livewire::test(UserManager::class)->viewData('appModules');
        $names = collect($apps)->pluck('name')->all();

        $this->assertContains('pos', $names);
        $this->assertNotContains('rental', $names, 'Rent A Car must not be offered on a café database.');
    }

    public function test_a_grant_for_an_app_the_business_type_hides_creates_no_access(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        app(ModuleManager::class)->install('rental');
        Setting::set('company.business_type', 'cafe');

        // Even a crafted payload ticking the hidden app grants nothing for it.
        Livewire::test(UserManager::class)
            ->set('name', 'Cafe Staff')
            ->set('email', 'cafestaff@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos', 'rental'])
            ->set('workspaces', [$this->mainId()])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'cafestaff@example.com')->firstOrFail();
        $acl = app(AccessControl::class);

        $this->assertTrue($acl->allows($user, 'pos.order', Permission::Read));
        $this->assertFalse($acl->allows($user, 'rental.vehicle', Permission::Read));

        // …and the dine-in models a café DOESN'T use are still granted (they're
        // part of its preset) while a model hidden by feature is not: pos.floor
        // is Restaurant-only, so a RETAIL database would drop it.
        $this->assertTrue($acl->allows($user, 'pos.floor', Permission::Read));
    }

    public function test_grants_are_scoped_per_database_by_each_ones_business_type(): void
    {
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true, 'email' => 'owner@erp.test']);
        $this->actingAs($owner);
        app(ModuleManager::class)->install('pos');

        // Main is a café; the tenant is a rental company. One tick-box, two
        // databases — each gets grants matching ITS OWN type.
        Setting::set('company.business_type', 'cafe');
        $workspace = app(WorkspaceManager::class)->provision('Rental Co', $owner, ['pos']);

        app(WorkspaceManager::class)->withTenant((string) $workspace->databasePath(), static function (): void {
            Setting::set('company.business_type', 'rental');
        });

        Livewire::test(UserManager::class)
            ->set('name', 'Shared Staff')
            ->set('email', 'shared@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos'])
            ->set('workspaces', [$this->mainId(), (int) $workspace->id])
            ->call('save')
            ->assertHasNoErrors();

        // Main (café) → POS access.
        $mainUser = User::query()->where('email', 'shared@example.com')->firstOrFail();
        $this->assertTrue(app(AccessControl::class)->allows($mainUser, 'pos.order', Permission::Read));

        // Tenant (rental) → the same tick granted NO POS access, because a
        // rental company doesn't run POS.
        app(WorkspaceManager::class)->withTenant((string) $workspace->databasePath(), function (): void {
            $tenantUser = User::query()->where('email', 'shared@example.com')->firstOrFail();
            $this->assertFalse(app(AccessControl::class)->allows($tenantUser, 'pos.order', Permission::Read));
        });
    }

    // ── Adding users from INSIDE a workspace (no trip back to Main) ──────────

    /**
     * Provision a workspace, then run the callback with the request switched
     * INTO it (default connection = that tenant), acting as its admin — the
     * state an admin is in after picking a database from "My database".
     *
     * @param  Closure(Workspace): void  $callback
     */
    private function insideWorkspace(Closure $callback): Workspace
    {
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true, 'email' => 'owner@erp.test']);
        $this->actingAs($owner);

        $workspace = app(WorkspaceManager::class)->provision('Kaleem', $owner, ['pos']);

        app(WorkspaceManager::class)->withTenant((string) $workspace->databasePath(), function () use ($callback, $workspace, $owner): void {
            // The tenancy middleware rebinds auth to the tenant copy (by email).
            $tenantOwner = User::query()->where('email', $owner->email)->first();
            $this->assertNotNull($tenantOwner);

            // `seedAdmins` copies admins but not the super-admin tier — make the
            // tenant copy the owner it represents, so these tests exercise the
            // workspace user CRUD directly (the regular-admin email-OTP gate on
            // edit/delete is covered by SuperAdminTest).
            $tenantOwner->is_super_admin = true;
            $tenantOwner->save();

            $this->actingAs($tenantOwner);

            $callback($workspace);
        });

        return $workspace;
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

    public function test_a_user_can_be_added_from_inside_a_workspace(): void
    {
        $workspace = $this->insideWorkspace(function (Workspace $workspace): void {
            // No database picker inside a workspace — the account belongs here.
            Livewire::test(UserManager::class)
                ->set('name', 'Kaleem Cashier')
                ->set('email', 'kcashier@example.com')
                ->set('password', 'secret12')
                ->set('apps', ['pos'])
                ->call('save')
                ->assertHasNoErrors();

            // The real account lives in THIS database, locked to it, view-only.
            $user = User::query()->where('email', 'kcashier@example.com')->first();
            $this->assertNotNull($user);
            $this->assertFalse($user->isAdmin());
            $this->assertSame((int) $workspace->id, (int) $user->home_workspace_id);
            $this->assertTrue(app(AccessControl::class)->allows($user, 'pos.order', Permission::Read));
            $this->assertFalse(app(AccessControl::class)->allows($user, 'pos.order', Permission::Write));
        });

        // Main got only a login shell — never an admin there, and locked to the
        // workspace so signing in lands them straight inside it.
        $shell = User::query()->where('email', 'kcashier@example.com')->first();
        $this->assertNotNull($shell);
        $this->assertFalse($shell->isAdmin());
        $this->assertSame((int) $workspace->id, (int) $shell->home_workspace_id);
    }

    public function test_an_admin_added_inside_a_workspace_is_an_admin_only_there(): void
    {
        $workspace = $this->insideWorkspace(function (): void {
            Livewire::test(UserManager::class)
                ->set('name', 'Kaleem Manager')
                ->set('email', 'kmanager@example.com')
                ->set('password', 'secret12')
                ->set('role', 'admin')
                ->call('save')
                ->assertHasNoErrors();

            $user = User::query()->where('email', 'kmanager@example.com')->first();
            $this->assertNotNull($user);
            $this->assertTrue($user->isAdmin());   // full admin in THIS database
        });

        // …but a plain, non-admin login shell on Main — they can't roam.
        $shell = User::query()->where('email', 'kmanager@example.com')->first();
        $this->assertNotNull($shell);
        $this->assertFalse($shell->isAdmin());
        $this->assertFalse($shell->isSuperAdmin());
        $this->assertSame((int) $workspace->id, (int) $shell->home_workspace_id);
    }

    public function test_an_email_owned_by_a_global_account_is_refused_inside_a_workspace(): void
    {
        // A global STAFF account on Main. It isn't copied into the workspace
        // (only admins are), so the tenant's own unique-email rule can't catch
        // the collision — the Main-identity guard is the only thing standing
        // between this and silently overwriting the login they'd sign in with.
        User::factory()->create(['is_admin' => false, 'email' => 'global@example.com', 'name' => 'Global Staff']);

        $this->insideWorkspace(function (): void {
            $this->assertSame(0, User::query()->where('email', 'global@example.com')->count());

            Livewire::test(UserManager::class)
                ->set('name', 'Impostor')
                ->set('email', 'global@example.com')
                ->set('password', 'secret12')
                ->call('save')
                ->assertHasErrors('email');

            $this->assertSame(0, User::query()->where('email', 'global@example.com')->count());
        });

        // The Main account is untouched — same name, still not locked anywhere.
        $global = User::query()->where('email', 'global@example.com')->first();
        $this->assertNotNull($global);
        $this->assertSame('Global Staff', $global->name);
        $this->assertNull($global->home_workspace_id);
    }

    public function test_a_global_account_stays_read_only_inside_a_workspace(): void
    {
        $this->insideWorkspace(function (): void {
            // The owner's tenant copy is a global account (no home workspace) —
            // editing it from inside the workspace must be a no-op.
            $global = User::query()->where('email', 'owner@erp.test')->first();
            $this->assertNotNull($global);
            $this->assertNull($global->home_workspace_id);

            Livewire::test(UserManager::class)
                ->call('editUser', $global->id)
                ->assertSet('editingId', null);
        });
    }

    public function test_deleting_a_workspace_user_removes_their_main_login_too(): void
    {
        $this->insideWorkspace(function (): void {
            $component = Livewire::test(UserManager::class)
                ->set('name', 'Temp Staff')
                ->set('email', 'temp@example.com')
                ->set('password', 'secret12')
                ->call('save')
                ->assertHasNoErrors();

            $user = User::query()->where('email', 'temp@example.com')->first();
            $this->assertNotNull($user);

            $component->call('deleteUser', $user->id);

            $this->assertSame(0, User::query()->where('email', 'temp@example.com')->count());
        });

        // The Main login shell is gone with them.
        $this->assertSame(0, User::query()->where('email', 'temp@example.com')->count());
    }
}

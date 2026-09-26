<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * An accountant provisioned before the role gained Write + Create
 * (2026-09-20) kept Read-only rules and could not add a rental receipt.
 */
final class AccountantGrantUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    public function test_an_old_read_only_accountant_grant_is_upgraded(): void
    {
        $accountant = User::factory()->create(['is_accountant' => true]);
        $staff = User::factory()->create();

        foreach ([$accountant, $staff] as $user) {
            $group = Group::query()->create(['code' => 'user:' . $user->id, 'name' => $user->name]);
            $user->groups()->attach($group->id);
            ModelAccess::query()->create([
                'name' => 'old grant',
                'model' => 'rental.receipt',
                'group_id' => $group->id,
                'perm_read' => true,
                'perm_write' => false,
                'perm_create' => false,
                'perm_unlink' => false,
            ]);
        }

        $acl = app(AccessControl::class);
        $this->assertFalse($acl->allows($accountant, 'rental.receipt', Permission::Create));

        $migration = require database_path('migrations/2026_09_26_100001_upgrade_accountant_grants_to_write_create.php');
        $migration->up();

        $this->assertTrue($acl->allows($accountant->fresh(), 'rental.receipt', Permission::Create));
        $this->assertTrue($acl->allows($accountant->fresh(), 'rental.receipt', Permission::Write));
        $this->assertFalse($acl->allows($accountant->fresh(), 'rental.receipt', Permission::Unlink));
        // A plain staff account is left view-only.
        $this->assertFalse($acl->allows($staff->fresh(), 'rental.receipt', Permission::Create));
    }
}

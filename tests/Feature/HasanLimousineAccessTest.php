<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Admin\StaffRole;
use App\Erp\Admin\UserProvisioner;
use App\Erp\Modules\ModuleManager;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Settings\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Hasan Makhlooq got 403 on a limousine invoice: his Wanaan account had no
 * Limousine app. The data migration adds it at the role he already holds,
 * keeping his other apps.
 */
final class HasanLimousineAccessTest extends TestCase
{
    use DatabaseMigrations;

    private function runFix(): void
    {
        (require database_path('migrations/2026_10_27_100001_give_hasan_makhlooq_limousine_access.php'))->up();
    }

    private function hasan(StaffRole $role, array $apps): User
    {
        $user = User::factory()->create(['name' => 'Hasan Makhlooq', 'email' => 'hasan.fuad@hotmail.com', 'is_admin' => false]);
        app(UserProvisioner::class)->grantApps($user, $apps, $role);

        return $user->fresh() ?? $user;
    }

    public function test_a_supervisor_without_limousine_gets_it_at_the_same_role(): void
    {
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
        Setting::set('company.business_type', 'rental_limousine');

        $hasan = $this->hasan(StaffRole::Supervisor, ['rental']);
        $acl = app(AccessControl::class);
        $this->assertFalse($acl->allows($hasan, 'limousine.invoice', Permission::Read));

        $this->runFix();
        $this->runFix(); // idempotent

        $this->assertTrue($acl->allows($hasan, 'limousine.invoice', Permission::Read));
        $this->assertTrue($acl->allows($hasan, 'limousine.invoice', Permission::Write));
        $this->assertFalse($acl->allows($hasan, 'limousine.invoice', Permission::Unlink));
        $this->assertTrue($acl->allows($hasan, 'rental.order', Permission::Write));
        $this->assertSame(StaffRole::Supervisor, app(UserProvisioner::class)->roleOf($hasan));
    }

    public function test_view_only_staff_stay_view_only(): void
    {
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
        Setting::set('company.business_type', 'rental_limousine');

        $hasan = $this->hasan(StaffRole::Staff, ['rental']);
        $this->runFix();

        $acl = app(AccessControl::class);
        $this->assertTrue($acl->allows($hasan, 'limousine.invoice', Permission::Read));
        $this->assertFalse($acl->allows($hasan, 'limousine.invoice', Permission::Write));
    }

    public function test_a_database_that_does_not_run_limousine_is_left_alone(): void
    {
        app(ModuleManager::class)->install('limousine');
        Setting::set('company.business_type', 'cafe');

        $hasan = $this->hasan(StaffRole::Staff, []);
        $this->runFix();

        $this->assertSame([], app(UserProvisioner::class)->appsOf($hasan));
    }

    public function test_nobody_else_is_touched(): void
    {
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
        Setting::set('company.business_type', 'rental_limousine');

        $other = User::factory()->create(['email' => 'someone@example.com', 'is_admin' => false]);
        app(UserProvisioner::class)->grantApps($other, ['rental'], StaffRole::Staff);
        $this->runFix();

        $this->assertSame(['rental'], app(UserProvisioner::class)->appsOf($other));
    }
}

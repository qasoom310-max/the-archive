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

/** The one-off grant of Limousine expenses to Via, Abbas, Hassan and Ali in Wanaan. */
final class LimoExpensesNamedAccessTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2026_11_23_100001_give_named_staff_limousine_expenses_access.php';

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
    }

    private function runGrant(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function may(User $user, Permission $permission): bool
    {
        return app(AccessControl::class)->allows($user->fresh(), 'limousine.expense', $permission);
    }

    public function test_the_four_get_view_add_and_edit_but_not_delete_in_wanaan(): void
    {
        Setting::set('company.name', 'Wanaan Car Rental W.L.L');
        $via = User::factory()->create(['name' => 'via', 'email' => 'via@example.com']);
        $abbas = User::factory()->create(['name' => 'Abbas Hamdan', 'email' => 'hamdanabbas98@gmail.com']);
        $hasan = User::factory()->create(['name' => 'Hasan Makhlooq', 'email' => 'hasan.fuad@hotmail.com']);
        $ali = User::factory()->create(['name' => 'Ali', 'email' => 'someone@example.com']);
        $other = User::factory()->create(['name' => 'Mariam', 'email' => 'mariam@example.com']);

        $this->runGrant();
        $this->runGrant(); // twice changes nothing

        foreach ([$via, $abbas, $hasan, $ali] as $user) {
            $this->assertTrue($this->may($user, Permission::Read), $user->name);
            $this->assertTrue($this->may($user, Permission::Create), $user->name);
            $this->assertTrue($this->may($user, Permission::Write), $user->name);
            $this->assertFalse($this->may($user, Permission::Unlink), $user->name);
        }
        $this->assertFalse($this->may($other, Permission::Read));
        $this->assertSame(1, \DB::table('res_group_user')->where('user_id', $via->id)->count());
    }

    public function test_it_survives_editing_their_apps_and_keeps_a_staff_member_staff(): void
    {
        Setting::set('company.name', 'Wanaan Car Rental W.L.L');
        $via = User::factory()->create(['name' => 'via', 'email' => 'via@example.com']);
        $provisioner = app(UserProvisioner::class);
        $provisioner->grantApps($via, ['limousine'], StaffRole::Staff);

        $this->runGrant();
        $provisioner->grantApps($via->fresh(), ['limousine'], StaffRole::Staff); // an edit in Settings → Users

        $this->assertTrue($this->may($via, Permission::Create));
        $this->assertSame(StaffRole::Staff, $provisioner->roleOf($via->fresh()));
    }

    public function test_taking_it_back_removes_the_access_and_leaves_their_apps(): void
    {
        Setting::set('company.name', 'Wanaan Car Rental W.L.L');
        $via = User::factory()->create(['name' => 'via', 'email' => 'via@example.com']);
        $provisioner = app(UserProvisioner::class);
        $provisioner->grantApps($via, ['limousine'], StaffRole::Staff);
        $readBefore = $this->may($via, Permission::Read);

        $this->runGrant();
        $this->assertTrue($this->may($via, Permission::Create));

        (require base_path('database/migrations/2026_11_23_100002_remove_named_staff_limousine_expenses_access.php'))->up();

        $this->assertFalse($this->may($via, Permission::Create));
        $this->assertFalse($this->may($via, Permission::Write));
        $this->assertSame($readBefore, $this->may($via, Permission::Read));
        $this->assertFalse(\DB::table('res_groups')->where('code', 'limousine_expenses')->exists());
        $this->assertSame(StaffRole::Staff, $provisioner->roleOf($via->fresh()));
    }

    public function test_nothing_changes_in_another_database(): void
    {
        Setting::set('company.name', 'Hashtag limo');
        $via = User::factory()->create(['name' => 'via', 'email' => 'via@example.com']);

        $this->runGrant();

        $this->assertFalse($this->may($via, Permission::Read));
        $this->assertFalse(\DB::table('res_groups')->where('code', 'limousine_expenses')->exists());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Security\TwoFactorGate;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\SettingsPage;
use App\Livewire\Settings\UserManager;
use App\Livewire\WorkspacesPage;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AdminActionOtp;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The super-admin tier (an owner role above admin): exclusive dashboard
 * system cards + business-type setting, protection of super-admin accounts
 * from regular admins, owner-only promotion, and the email-OTP 2FA a regular
 * admin must pass to edit/delete a user or a database.
 */
final class SuperAdminTest extends TestCase
{
    use DatabaseMigrations;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => false]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
    }

    public function test_super_admin_is_a_superset_and_exempt_from_otp(): void
    {
        $gate = app(TwoFactorGate::class);

        $super = $this->superAdmin();
        $this->assertTrue($super->isAdmin());
        $this->assertTrue($super->isSuperAdmin());
        $this->assertFalse($gate->required($super));

        $admin = $this->admin();
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isSuperAdmin());
        $this->assertTrue($gate->required($admin));
    }

    public function test_dashboard_system_cards_are_super_admin_only(): void
    {
        $this->actingAs($this->superAdmin());
        Livewire::test(Dashboard::class)
            ->assertSee('Installed apps')
            ->assertSee('Registered models');

        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)
            ->assertDontSee('Installed apps')
            ->assertDontSee('Registered models');
    }

    public function test_business_type_setting_is_super_admin_only(): void
    {
        (new SettingSeeder())->run();

        $this->actingAs($this->superAdmin());
        Livewire::test(SettingsPage::class)->assertSee('Business Type');

        $this->actingAs($this->admin());
        Livewire::test(SettingsPage::class)->assertDontSee('Business Type');
    }

    public function test_business_type_cannot_be_written_by_a_regular_admin(): void
    {
        (new SettingSeeder())->run();
        $this->actingAs($this->admin());

        // A crafted payload injecting the forbidden key is dropped by canSee().
        Livewire::test(SettingsPage::class)
            ->set('form', [['key' => 'company.business_type', 'label' => 'Business Type', 'type' => 'string', 'group' => 'General', 'description' => null, 'value' => 'restaurant']])
            ->call('save');

        $this->assertNotSame('restaurant', \App\Erp\Settings\Setting::get('company.business_type'));
    }

    public function test_regular_admin_must_pass_email_otp_to_delete_a_user(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->actingAs($admin);

        $victim = User::factory()->create(['is_admin' => false]);

        $component = Livewire::test(UserManager::class)
            ->call('deleteUser', $victim->id)
            ->assertSet('otpOpen', true);

        // Not deleted yet — awaiting the code.
        $this->assertNotNull(User::query()->find($victim->id));

        $code = null;
        Notification::assertSentTo($admin, AdminActionOtp::class, function (AdminActionOtp $n) use (&$code): bool {
            $code = $n->code;

            return true;
        });
        $this->assertIsString($code);

        $component->set('otpCode', $code)->call('submitOtp')->assertSet('otpOpen', false);
        $this->assertNull(User::query()->find($victim->id));
    }

    public function test_wrong_otp_does_not_delete(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());
        $victim = User::factory()->create(['is_admin' => false]);

        Livewire::test(UserManager::class)
            ->call('deleteUser', $victim->id)
            ->set('otpCode', '000000')
            ->call('submitOtp')
            ->assertHasErrors('otpCode');

        $this->assertNotNull(User::query()->find($victim->id));
    }

    public function test_super_admin_deletes_a_user_without_otp(): void
    {
        Notification::fake();
        $this->actingAs($this->superAdmin());
        $victim = User::factory()->create(['is_admin' => false]);

        Livewire::test(UserManager::class)
            ->call('deleteUser', $victim->id)
            ->assertSet('otpOpen', false);

        $this->assertNull(User::query()->find($victim->id));
        Notification::assertNothingSent();
    }

    public function test_regular_admin_cannot_delete_or_edit_a_super_admin(): void
    {
        $this->actingAs($this->admin());
        $super = $this->superAdmin();

        Livewire::test(UserManager::class)
            ->call('deleteUser', $super->id)
            ->assertSet('otpOpen', false);          // never even challenged
        $this->assertNotNull(User::query()->find($super->id));

        Livewire::test(UserManager::class)
            ->call('editUser', $super->id)
            ->assertSet('editingId', null);          // load refused
    }

    public function test_only_a_super_admin_can_promote_another(): void
    {
        // The role is set in the edit form now (there's no inline toggle).
        // Owner promotes a normal user, then demotes.
        $this->actingAs($this->superAdmin());
        $target = User::factory()->create(['is_admin' => false]);

        Livewire::test(UserManager::class)
            ->call('editUser', $target->id)
            ->set('role', 'super')
            ->call('save')
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertTrue($target->isSuperAdmin());
        $this->assertTrue($target->isAdmin()); // the tier is a superset

        Livewire::test(UserManager::class)
            ->call('editUser', $target->id)
            ->set('role', 'staff')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($target->fresh()?->isSuperAdmin());

        // A regular admin can't hand out the owner tier — the role isn't even
        // assignable for them, so validation rejects it.
        $this->actingAs($this->admin());
        $other = User::factory()->create(['is_admin' => false]);

        Livewire::test(UserManager::class)
            ->call('editUser', $other->id)
            ->set('role', 'super')
            ->call('save')
            ->assertHasErrors('role');

        $this->assertFalse($other->fresh()?->isSuperAdmin());
    }

    public function test_regular_admin_must_pass_otp_to_rename_a_database(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->actingAs($admin);

        $ws = Workspace::query()->create(['name' => 'Old', 'slug' => 'old', 'database' => 'old.sqlite', 'is_main' => false]);

        $component = Livewire::test(WorkspacesPage::class)
            ->call('startRename', $ws->id)
            ->set('editName', 'New name')
            ->call('rename')
            ->assertSet('otpOpen', true);

        $this->assertSame('Old', $ws->fresh()?->name); // not renamed yet

        $code = null;
        Notification::assertSentTo($admin, AdminActionOtp::class, function (AdminActionOtp $n) use (&$code): bool {
            $code = $n->code;

            return true;
        });

        $component->set('otpCode', (string) $code)->call('submitOtp')->assertSet('otpOpen', false);
        $this->assertSame('New name', $ws->fresh()?->name);
    }

    public function test_the_admin_code_cannot_be_guessed_indefinitely(): void
    {
        // The 6-digit code guards user + database deletion, so it is only ever
        // reached by someone who already holds an admin session — exactly the
        // case it exists to stop. Unlimited guesses made it decoration.
        $admin = User::factory()->create(['is_admin' => true, 'is_super_admin' => false]);
        $gate = app(\App\Erp\Security\TwoFactorGate::class);

        $this->assertTrue($gate->challenge($admin, 'user.delete'));

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($gate->verify($admin, 'user.delete', '000000'));
        }

        // The challenge is gone, so even the right code no longer works —
        // a new (rate-limited) one has to be requested.
        $this->assertSame(0, \App\Models\AdminOtpChallenge::query()->count());
    }

    public function test_fresh_codes_are_rate_limited(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_super_admin' => false]);
        $gate = app(\App\Erp\Security\TwoFactorGate::class);

        for ($i = 0; $i < 6; $i++) {
            $this->assertTrue($gate->challenge($admin, 'user.delete'), "code {$i} should be issued");
        }

        $this->assertFalse($gate->challenge($admin, 'user.delete'), 'the 7th request must be refused');
        // A different action is unaffected.
        $this->assertTrue($gate->challenge($admin, 'workspace.delete'));
    }
}

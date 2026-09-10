<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Admin\StaffRole;
use App\Erp\Admin\UserProvisioner;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The CLI twin of Settings → Users. It exists for the case the screen can't
 * serve: a global account that the Users tab renders read-only from inside a
 * workspace, or nobody left who can log in at the right tier.
 */
final class EnsureStaffUserCommandTest extends TestCase
{
    use DatabaseMigrations;

    public function test_it_creates_an_accountant_who_can_confirm_payments(): void
    {
        $this->artisan('user:ensure', [
            'email' => 'account@example.com',
            'password' => 'a-strong-pass',
            '--name' => 'Prejith',
            '--role' => 'accountant',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'account@example.com')->firstOrFail();

        $this->assertSame('Prejith', $user->name);
        $this->assertTrue(Hash::check('a-strong-pass', (string) $user->password));
        $this->assertTrue($user->canConfirmPayments());
        $this->assertFalse($user->isAdmin());
        $this->assertSame(StaffRole::Accountant, app(UserProvisioner::class)->roleOf($user));
    }

    public function test_re_running_resets_the_password_instead_of_duplicating(): void
    {
        $args = [
            'email' => 'account@example.com',
            'password' => 'first-password',
            '--role' => 'accountant',
        ];

        $this->artisan('user:ensure', $args)->assertSuccessful();
        $this->artisan('user:ensure', array_merge($args, ['password' => 'second-password']))->assertSuccessful();

        $this->assertSame(1, User::query()->where('email', 'account@example.com')->count());

        $user = User::query()->where('email', 'account@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('second-password', (string) $user->password));
    }

    public function test_it_defaults_the_name_from_the_email(): void
    {
        $this->artisan('user:ensure', [
            'email' => 'prejith@example.com',
            'password' => 'a-strong-pass',
        ])->assertSuccessful();

        $this->assertSame('prejith', User::query()->where('email', 'prejith@example.com')->value('name'));
    }

    public function test_it_refuses_a_short_password(): void
    {
        $this->artisan('user:ensure', [
            'email' => 'account@example.com',
            'password' => 'short',
        ])->assertFailed();

        $this->assertNull(User::query()->where('email', 'account@example.com')->first());
    }

    public function test_it_refuses_an_unknown_role(): void
    {
        $this->artisan('user:ensure', [
            'email' => 'account@example.com',
            'password' => 'a-strong-pass',
            '--role' => 'wizard',
        ])->assertFailed();

        $this->assertNull(User::query()->where('email', 'account@example.com')->first());
    }

    public function test_it_no_ops_without_an_email(): void
    {
        $this->artisan('user:ensure')->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `admin:ensure` is the locked-out recovery path: it creates or resets an admin
 * login (idempotent), and does nothing without credentials.
 */
final class EnsureAdminCommandTest extends TestCase
{
    use DatabaseMigrations;

    public function test_it_creates_an_admin_login(): void
    {
        $this->artisan('admin:ensure', ['email' => 'res@wanaan-bh.com', 'password' => 'H1234567h'])
            ->assertSuccessful();

        $user = User::query()->where('email', 'res@wanaan-bh.com')->sole();
        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check('H1234567h', $user->password));
    }

    public function test_it_resets_an_existing_users_password_and_promotes_to_admin(): void
    {
        User::factory()->create(['email' => 'res@wanaan-bh.com', 'is_admin' => false, 'password' => Hash::make('old-one')]);

        $this->artisan('admin:ensure', ['email' => 'res@wanaan-bh.com', 'password' => 'new-pass-9'])
            ->assertSuccessful();

        $user = User::query()->where('email', 'res@wanaan-bh.com')->sole();
        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check('new-pass-9', $user->password));
        $this->assertSame(1, User::query()->where('email', 'res@wanaan-bh.com')->count()); // updated, not duplicated
    }

    public function test_it_does_nothing_without_credentials(): void
    {
        $before = User::query()->count();

        $this->artisan('admin:ensure')->assertSuccessful();

        $this->assertSame($before, User::query()->count());
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('admin:ensure', ['email' => 'res@wanaan-bh.com', 'password' => 'short'])
            ->assertFailed();

        $this->assertNull(User::query()->where('email', 'res@wanaan-bh.com')->first());
    }
}

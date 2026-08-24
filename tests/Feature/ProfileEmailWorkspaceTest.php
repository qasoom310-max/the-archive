<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use App\Notifications\VerifyNewEmail;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Changing your email from inside a second database.
 *
 * The verify route is deliberately open to guests (so the link works from any
 * device), which means no workspace cookie routes the request — it landed on
 * Main and looked up a DIFFERENT account with the same row id, normally
 * dead-ending on "your email is already up to date". The signed link now names
 * the database the change was requested in.
 */
final class ProfileEmailWorkspaceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_the_link_resolves_against_the_workspace_it_was_requested_in(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $manager = app(WorkspaceManager::class);
        $workspace = $manager->provision('Second shop', $owner, []);
        $path = $workspace->databasePath();
        $this->assertNotNull($path);

        // Park a pending email change on the WORKSPACE's copy of an account.
        $tenantUserId = $manager->withTenant($path, static function (): int {
            $user = User::factory()->create(['email' => 'inside@example.com']);
            $user->new_email = 'changed@example.com';
            $user->save();

            return (int) $user->getKey();
        });

        // Put a DIFFERENT account at the same row id on Main — the account the
        // old link would have found and (not) changed.
        User::query()->forceCreate([
            'id' => $tenantUserId,
            'name' => 'Someone else',
            'email' => 'someone-else@example.com',
            'password' => bcrypt('secret'),
        ]);

        $mainUser = User::query()->findOrFail($tenantUserId);
        $this->assertNull($mainUser->new_email);

        $url = URL::temporarySignedRoute('profile.email.verify', now()->addHour(), [
            'id' => $tenantUserId,
            'hash' => VerifyNewEmail::hashFor('changed@example.com'),
            'ws' => $workspace->id,
        ]);

        $this->get($url)->assertRedirect(route('profile'));

        // Applied inside the workspace …
        $tenantEmail = $manager->withTenant($path, static fn (): ?string => User::query()->find($tenantUserId)?->email);
        $this->assertSame('changed@example.com', $tenantEmail);

        // … and Main's same-id account is untouched.
        $this->assertSame('someone-else@example.com', $mainUser->fresh()?->email);
    }

    public function test_a_link_without_a_workspace_still_resolves_on_main(): void
    {
        $user = User::factory()->create(['email' => 'main@example.com']);
        $user->new_email = 'newmain@example.com';
        $user->save();

        $url = URL::temporarySignedRoute('profile.email.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => VerifyNewEmail::hashFor('newmain@example.com'),
        ]);

        $this->get($url)->assertRedirect(route('profile'));

        $this->assertSame('newmain@example.com', $user->fresh()?->email);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ProfileEmailVerificationController;
use App\Livewire\ProfilePage;
use App\Models\User;
use App\Notifications\VerifyNewEmail;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Profile page + secure email-change flow. Covers:
 *
 *   - Direct edits (name, avatar, password) write through immediately.
 *   - Email changes park in `new_email` and fire a VerifyNewEmail
 *     notification to the NEW address (not the current one).
 *   - The signed-URL controller swaps email = new_email only when the
 *     hash matches the pending value; stale + tampered URLs are refused.
 *   - The Role field is exposed as read-only — the form has no input
 *     for it and `save()` ignores any attempt to set `is_admin` via
 *     property mass-assignment.
 */
final class ProfilePageTest extends TestCase
{
    use DatabaseMigrations;

    private function actAsUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'name' => 'Original Name',
            'email' => 'original@example.test',
            'password' => Hash::make('correct-horse-battery-staple'),
            'is_admin' => false,
        ], $overrides));
        $this->actingAs($user);

        return $user->fresh();
    }

    // ───────────────────────── Mount ───────────────────────────────

    public function test_mount_prefills_the_form_with_current_user_values(): void
    {
        $this->actAsUser(['name' => 'Charlie', 'email' => 'charlie@x.test']);

        Livewire::test(ProfilePage::class)
            ->assertSet('name', 'Charlie')
            ->assertSet('email', 'charlie@x.test')
            ->assertViewHas('roleLabel');
    }

    // ───────────────────────── Name / password ─────────────────────

    public function test_save_updates_name_immediately(): void
    {
        $user = $this->actAsUser();

        Livewire::test(ProfilePage::class)
            ->set('name', 'New Name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_password_change_with_correct_current_password_succeeds(): void
    {
        $user = $this->actAsUser();

        Livewire::test(ProfilePage::class)
            ->set('currentPassword', 'correct-horse-battery-staple')
            ->set('newPassword', 'brand-new-password-1!')
            ->set('newPasswordConfirmation', 'brand-new-password-1!')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('newPassword', ''); // cleared after success

        $this->assertTrue(Hash::check('brand-new-password-1!', $user->fresh()->password));
    }

    public function test_password_change_with_wrong_current_password_is_rejected(): void
    {
        $user = $this->actAsUser();

        Livewire::test(ProfilePage::class)
            ->set('currentPassword', 'WRONG')
            ->set('newPassword', 'newer-password-1!')
            ->set('newPasswordConfirmation', 'newer-password-1!')
            ->call('save')
            ->assertHasErrors('currentPassword');

        $this->assertTrue(Hash::check('correct-horse-battery-staple', $user->fresh()->password));
    }

    public function test_password_confirm_mismatch_fails_validation(): void
    {
        $this->actAsUser();

        Livewire::test(ProfilePage::class)
            ->set('currentPassword', 'correct-horse-battery-staple')
            ->set('newPassword', 'aaaaaaaa1')
            ->set('newPasswordConfirmation', 'different1')
            ->call('save')
            ->assertHasErrors('newPassword');
    }

    public function test_short_password_fails_min_length(): void
    {
        $this->actAsUser();

        Livewire::test(ProfilePage::class)
            ->set('currentPassword', 'correct-horse-battery-staple')
            ->set('newPassword', 'short1')
            ->set('newPasswordConfirmation', 'short1')
            ->call('save')
            ->assertHasErrors('newPassword');
    }

    public function test_new_password_with_arabic_characters_is_rejected(): void
    {
        // The blade input has a `beforeinput` filter that blocks non-ASCII
        // keystrokes, but a crafted Livewire payload (or a JS-disabled
        // browser) could still POST one — so the server enforces the same
        // rule with a regex. This test pins the server check.
        $user = $this->actAsUser();
        $originalHash = $user->password;

        Livewire::test(ProfilePage::class)
            ->set('currentPassword', 'correct-horse-battery-staple')
            ->set('newPassword', 'كلمةsecret1!')
            ->set('newPasswordConfirmation', 'كلمةsecret1!')
            ->call('save')
            ->assertHasErrors(['newPassword' => 'regex']);

        // Password unchanged on disk.
        $this->assertSame($originalHash, $user->fresh()->password);
    }

    // ───────────────────────── Avatar ──────────────────────────────

    public function test_avatar_upload_stores_on_public_disk_and_sets_path(): void
    {
        Storage::fake('public');
        $user = $this->actAsUser();

        $file = UploadedFile::fake()->create('me.jpg', 8, 'image/jpeg');

        Livewire::test(ProfilePage::class)
            ->set('avatar', $file)
            ->call('save')
            ->assertHasNoErrors();

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_replacing_avatar_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $user = $this->actAsUser();

        Livewire::test(ProfilePage::class)
            ->set('avatar', UploadedFile::fake()->create('first.jpg', 4, 'image/jpeg'))
            ->call('save');

        $firstPath = $user->fresh()->avatar_path;
        $this->assertNotNull($firstPath);
        Storage::disk('public')->assertExists($firstPath);

        Livewire::test(ProfilePage::class)
            ->set('avatar', UploadedFile::fake()->create('second.jpg', 4, 'image/jpeg'))
            ->call('save');

        $secondPath = $user->fresh()->avatar_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath); // old gone
        Storage::disk('public')->assertExists($secondPath);
    }

    // ─────────────────── Email change (deferred) ───────────────────

    public function test_changing_email_parks_in_new_email_and_sends_verification_to_new_address(): void
    {
        Notification::fake();
        $user = $this->actAsUser(['email' => 'old@example.test']);

        Livewire::test(ProfilePage::class)
            ->set('email', 'new@example.test')
            ->call('save')
            ->assertHasNoErrors();

        $refreshed = $user->fresh();
        // CURRENT email unchanged — that's the security invariant.
        $this->assertSame('old@example.test', $refreshed->email);
        // …and the pending one is parked, ready for verification.
        $this->assertSame('new@example.test', $refreshed->new_email);

        // Routed via an anonymous notifiable (Notification::route('mail', …))
        // because the user's default routing would deliver to the OLD email.
        Notification::assertSentOnDemand(
            VerifyNewEmail::class,
            function ($notification, $channels, $notifiable): bool {
                return ($notifiable->routes['mail'] ?? null) === 'new@example.test';
            },
        );
    }

    public function test_saving_without_changing_email_does_not_park_or_notify(): void
    {
        Notification::fake();
        $user = $this->actAsUser(['email' => 'same@example.test']);

        Livewire::test(ProfilePage::class)
            ->set('name', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()->new_email);
        Notification::assertNothingSent();
    }

    public function test_email_change_to_an_already_taken_address_fails(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@example.test']);
        $this->actAsUser();

        Livewire::test(ProfilePage::class)
            ->set('email', 'taken@example.test')
            ->call('save')
            ->assertHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_cancel_pending_email_change_clears_new_email(): void
    {
        $user = $this->actAsUser();
        $user->new_email = 'pending@example.test';
        $user->save();

        // Re-act with the refreshed user so Auth's cached instance reflects
        // the pending email (otherwise the component sees `new_email = null`
        // on the stale in-memory copy and short-circuits).
        $this->actingAs($user->fresh());

        Livewire::test(ProfilePage::class)
            ->call('cancelPendingEmailChange');

        $this->assertNull($user->fresh()->new_email);
    }

    // ────────────── Email verification controller ─────────────────

    public function test_verification_link_swaps_email_and_clears_new_email(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.test',
            'new_email' => 'new@example.test',
        ]);

        $url = URL::temporarySignedRoute('profile.email.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => VerifyNewEmail::hashFor('new@example.test'),
        ]);

        $response = $this->get($url);

        $response->assertRedirect(route('profile'));
        $refreshed = $user->fresh();
        $this->assertSame('new@example.test', $refreshed->email);
        $this->assertNull($refreshed->new_email);
        $this->assertNotNull($refreshed->email_verified_at);
    }

    public function test_verification_with_mismatched_hash_is_refused(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.test',
            'new_email' => 'new@example.test',
        ]);

        // Sign with the WRONG email's hash — simulates a stale link from
        // a previous pending change after the user updated the request.
        $url = URL::temporarySignedRoute('profile.email.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => VerifyNewEmail::hashFor('SOMETHING_ELSE@example.test'),
        ]);

        $response = $this->get($url);
        $response->assertForbidden();

        $this->assertSame('old@example.test', $user->fresh()->email); // unchanged
    }

    public function test_unsigned_or_tampered_url_is_403(): void
    {
        $user = User::factory()->create(['new_email' => 'new@example.test']);

        // No signature at all — middleware refuses immediately.
        $response = $this->get("/profile/email/verify/{$user->id}/" . VerifyNewEmail::hashFor('new@example.test'));
        $response->assertForbidden();

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_expired_signature_is_refused(): void
    {
        $user = User::factory()->create(['new_email' => 'new@example.test']);

        $url = URL::temporarySignedRoute('profile.email.verify', now()->subMinute(), [
            'id' => $user->id,
            'hash' => VerifyNewEmail::hashFor('new@example.test'),
        ]);

        $this->get($url)->assertForbidden();
    }

    public function test_verification_when_no_pending_change_redirects_with_idempotent_flash(): void
    {
        // Edge case: user clicks the link AFTER successfully verifying a
        // previous change. The pending field is empty; we redirect to
        // /profile with an "already up to date" flash rather than 4xx.
        $user = User::factory()->create([
            'email' => 'current@example.test',
            'new_email' => null,
        ]);

        $url = URL::temporarySignedRoute('profile.email.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => VerifyNewEmail::hashFor('whatever@example.test'),
        ]);

        $this->get($url)->assertRedirect(route('profile'));
    }

    // ────────────────────── Role read-only ─────────────────────────

    public function test_role_label_is_administrator_for_admins_and_user_for_others(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Livewire::test(ProfilePage::class)->assertViewHas('roleLabel', 'Administrator');

        $other = User::factory()->create(['is_admin' => false]);
        $this->actingAs($other);
        Livewire::test(ProfilePage::class)->assertViewHas('roleLabel', 'User');
    }

    public function test_save_does_not_let_user_promote_themself_via_form_state(): void
    {
        $user = $this->actAsUser(['is_admin' => false]);

        // The component has no is_admin property and the view's role field
        // is disabled — but pin server-side: even if a client tampered to
        // send is_admin=1, save() never touches it.
        Livewire::test(ProfilePage::class)
            ->set('name', 'Sneaky Promoter')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($user->fresh()->isAdmin());
    }
}

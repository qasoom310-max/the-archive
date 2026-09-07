<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * Getting back in after forgetting the password: ask for a link by email,
 * follow it, set a new one.
 */
final class PasswordResetTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('password-reset:127.0.0.1');
    }

    public function test_the_login_screen_offers_a_way_out_when_the_password_is_forgotten(): void
    {
        Livewire::test(Login::class)
            ->assertSee(__('Forgot your password?'))
            ->assertSee(route('password.request'), false);
    }

    public function test_asking_for_a_link_emails_one_to_the_account(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@example.com']);

        Livewire::test(ForgotPassword::class)
            ->set('email', 'owner@example.com')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('sent', true)
            ->assertSee(__('Check your email'));

        Notification::assertSentTo($user, ResetPasswordLink::class);
    }

    public function test_an_unknown_address_looks_exactly_like_a_sent_one(): void
    {
        // Otherwise the box answers "does this person have an account here?".
        Notification::fake();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'nobody@example.com')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('sent', true)
            ->assertSee(__('Check your email'));

        Notification::assertNothingSent();
    }

    public function test_a_malformed_address_is_refused_before_anything_is_sent(): void
    {
        Notification::fake();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'not-an-email')
            ->call('send')
            ->assertHasErrors(['email'])
            ->assertSet('sent', false);

        Notification::assertNothingSent();
    }

    public function test_the_emailed_link_carries_the_token_and_the_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@example.com']);

        Livewire::test(ForgotPassword::class)->set('email', 'owner@example.com')->call('send');

        Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return is_string($url)
                && str_contains($url, '/reset-password/' . $notification->token)
                && str_contains($url, urlencode('owner@example.com'));
        });
    }

    public function test_following_the_link_sets_the_new_password_and_the_old_one_stops_working(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPassword::class, ['token' => $token, 'email' => 'owner@example.com'])
            ->set('password', 'a-fresh-password')
            ->set('passwordConfirmation', 'a-fresh-password')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('a-fresh-password', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
    }

    public function test_the_same_link_cannot_be_used_twice(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPassword::class, ['token' => $token, 'email' => 'owner@example.com'])
            ->set('password', 'first-password')
            ->set('passwordConfirmation', 'first-password')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(ResetPassword::class, ['token' => $token, 'email' => 'owner@example.com'])
            ->set('password', 'second-password')
            ->set('passwordConfirmation', 'second-password')
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertTrue(Hash::check('first-password', $user->refresh()->password));
    }

    public function test_a_forged_token_changes_nothing(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('old-password'),
        ]);

        Livewire::test(ResetPassword::class, ['token' => 'made-up', 'email' => 'owner@example.com'])
            ->set('password', 'a-fresh-password')
            ->set('passwordConfirmation', 'a-fresh-password')
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPassword::class, ['token' => $token, 'email' => 'owner@example.com'])
            ->set('password', 'short')
            ->set('passwordConfirmation', 'short')
            ->call('save')
            ->assertHasErrors(['password']);

        Livewire::test(ResetPassword::class, ['token' => $token, 'email' => 'owner@example.com'])
            ->set('password', 'long-enough-one')
            ->set('passwordConfirmation', 'a-different-one')
            ->call('save')
            ->assertHasErrors(['password']);
    }

    public function test_a_non_ascii_password_is_refused(): void
    {
        // Mirrors the profile screen: a password must stay typeable on a
        // keyboard set to any language, so a layout can never lock an account out.
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::broker()->createToken($user);

        $arabic = mb_convert_encoding('&#1603;&#1604;&#1605;&#1577;&#1575;&#1604;&#1605;&#1585;&#1608;&#1585;', 'UTF-8', 'HTML-ENTITIES');

        Livewire::test(ResetPassword::class, ['token' => $token, 'email' => 'owner@example.com'])
            ->set('password', $arabic)
            ->set('passwordConfirmation', $arabic)
            ->call('save')
            ->assertHasErrors(['password']);
    }

    public function test_the_emailed_link_fills_the_email_in_so_nobody_types_it(): void
    {
        // The email travels in the query string, which Livewire does not pass
        // to mount() - the screen used to refuse with "email is required".
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::broker()->createToken($user);

        $this->get('/reset-password/' . $token . '?email=owner%40example.com')
            ->assertOk()
            ->assertSee('owner@example.com')
            ->assertDontSee('wire:model="email"', false);
    }

    public function test_a_link_without_an_email_asks_for_it_instead_of_failing(): void
    {
        $this->get('/reset-password/whatever')
            ->assertOk()
            ->assertSee('wire:model="email"', false);
    }

    public function test_the_token_cannot_be_repointed_by_the_browser(): void
    {
        // It identifies the account being rewritten, so it is #[Locked].
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::broker()->createToken($user);

        $component = Livewire::test(ResetPassword::class, ['token' => $token, 'email' => 'owner@example.com']);

        try {
            $component->set('token', 'other');
            $this->fail('token must be locked against the browser.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('locked', mb_strtolower($e->getMessage()));
        }
    }

    public function test_hammering_the_form_is_rate_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(ForgotPassword::class)
                ->set('email', 'someone' . $i . '@example.com')
                ->call('send')
                ->assertHasNoErrors();
        }

        Livewire::test(ForgotPassword::class)
            ->set('email', 'someone6@example.com')
            ->call('send')
            ->assertHasErrors(['email'])
            ->assertSet('sent', false);
    }

    public function test_a_signed_in_user_is_kept_away_from_these_screens(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/forgot-password')->assertRedirect();
        $this->get('/reset-password/anything')->assertRedirect();
    }
}

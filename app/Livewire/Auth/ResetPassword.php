<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sets a new password from an emailed link. The token is the route segment;
 * the email rides alongside it in the QUERY STRING, which Livewire does not
 * hand to mount() - so it is read from the request explicitly. The broker
 * checks the token/email pair, so neither half is any use on its own and a
 * used or expired token is refused.
 *
 * Only the token is #[Locked]. The email is not a secret and the token is
 * bound to it, so letting it be typed (when a link arrives without one) can
 * never reset anybody else's account.
 */
#[Layout('components.layouts.guest')]
#[Title('Set a new password')]
final class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(string $token, ?string $email = null): void
    {
        $this->token = $token;
        // The mailed link is /reset-password/{token}?email=...; the query
        // string is not a route parameter, so pull it off the request.
        $query = request()->query('email');
        $this->email = $email ?? (is_string($query) ? $query : '');
    }

    public function save(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            // Same rule the profile screen applies: printable ASCII only, so a
            // password can always be retyped on a keyboard set to any language.
            'password' => ['required', 'string', 'min:8', 'regex:/^[\x20-\x7E]*$/', 'confirmed:passwordConfirmation'],
        ], [
            'password.regex' => __('Password may only contain English letters, digits, and symbols.'),
        ]);

        $status = Password::broker()->reset([
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password,
            'token' => $this->token,
        ], function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __('This link is no longer valid. Please request a new one.'),
            ]);
        }

        session()->flash('status', __('Your password has been changed. Please sign in.'));

        $this->redirect(route('login'));
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password');
    }
}

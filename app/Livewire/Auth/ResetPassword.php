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
 * Sets a new password from an emailed link. The token comes from the URL and
 * the email rides alongside it; the broker checks the pair, so neither half is
 * any use on its own and a used or expired token is refused.
 *
 * Both are #[Locked]: they identify the account being rewritten, and Livewire
 * lets the browser set any unlocked public property.
 */
#[Layout('components.layouts.guest')]
#[Title('Set a new password')]
final class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    #[Locked]
    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(string $token, ?string $email = null): void
    {
        $this->token = $token;
        $this->email = $email ?? '';
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

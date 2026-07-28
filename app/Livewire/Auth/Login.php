<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Sign in')]
final class Login extends Component
{
    /** Email address *or* username — staff accounts may have no email. */
    #[Validate('required|string')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        $key = 'login:' . mb_strtolower($this->email) . '|' . request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => "Too many attempts. Try again in " . RateLimiter::availableIn($key) . "s.",
            ]);
        }

        // Staff without an email sign in by username (name); seeded
        // accounts keep using their email. Pick the field accordingly.
        $field = filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false ? 'email' : 'name';

        if (! Auth::attempt([$field => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        // Land on the database chooser, not straight into the app: every sign-in
        // gets to pick which business database to enter rather than being
        // dropped into whichever one a stale cookie remembered. The chooser
        // itself sends single-database / locked users straight in, so it only
        // actually stops to ask when there's a real choice. A full redirect (not
        // wire:navigate) so it lands on the plain GET controller cleanly.
        $this->redirect(route('workspaces.choose'));
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}

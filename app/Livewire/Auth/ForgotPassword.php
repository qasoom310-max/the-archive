<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

/**
 * "I've forgotten my password" — takes an email and sends a reset link.
 *
 * The account always lives in MAIN: {@see \App\Http\Middleware\SetActiveWorkspace}
 * short-circuits guests, so this screen (and the reset screen) read and write
 * the canonical identity store, which is the same row {@see Login} authenticates
 * against. A workspace-only staff row is never the one that holds the password.
 *
 * The reply is deliberately the SAME whether or not the address is on file:
 * a different message for a hit would turn this box into a way of asking
 * "does this person have an account here?". The broker's own per-account
 * throttle stops a mailbox being flooded; the limiter here stops one visitor
 * working through a list of addresses.
 */
#[Layout('components.layouts.guest')]
#[Title('Forgot password')]
final class ForgotPassword extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    /** Set once the link has gone out, so the form is replaced by the notice. */
    public bool $sent = false;

    public function send(): void
    {
        $this->validate();

        $key = 'password-reset:' . request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Try again in :seconds s.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        RateLimiter::hit($key, 300);

        // Never branch on the result: a missing account and a sent email must
        // look identical from out here. A genuine send failure (SMTP down) is
        // reported, because that is our fault, not a hint about the account.
        try {
            $status = Password::broker()->sendResetLink(['email' => $this->email]);
        } catch (Throwable $e) {
            // The broker call reaches out to the mail transport synchronously
            // (this notification is deliberately not queued), so a transport
            // exception propagates straight up here uncaught unless we catch
            // it ourselves — otherwise the button just hangs/errors with no
            // explanation, indistinguishable from "nothing happened".
            Log::error('Password reset email failed to send.', ['exception' => $e]);

            throw ValidationException::withMessages([
                'email' => __("We couldn't send the email right now. Please try again in a few minutes."),
            ]);
        }

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => __('A link was sent recently. Please check your inbox, or try again in a minute.'),
            ]);
        }

        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password');
    }
}

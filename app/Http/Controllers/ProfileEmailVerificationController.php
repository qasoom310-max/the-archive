<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use App\Notifications\VerifyNewEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Completes the email-change flow started on the profile page. The
 * route is signed (Laravel's `signed` middleware verifies the URL
 * wasn't tampered with) so this controller only needs to:
 *   1. confirm the URL's `hash` still matches the user's CURRENT
 *      `new_email` (otherwise the user changed their mind and picked
 *      a different new email — stale link must be refused);
 *   2. swap `email = new_email`, null out `new_email`;
 *   3. redirect back to the profile page with a success flash.
 *
 * Login is NOT required — matches Laravel's stock email-verification
 * convention. The signature is the proof of intent; the hash binds the
 * URL to a specific pending email.
 */
final class ProfileEmailVerificationController
{
    public function __invoke(Request $request, int $id, string $hash): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            throw new HttpException(403, 'The verification link is invalid or has expired.');
        }

        // The link names the database the change was requested in. A user id is
        // only unique within one, and this route is deliberately open to guests
        // — so without this, a link opened signed-out or on another device
        // resolved against MAIN and looked up a different account (usually
        // dead-ending on "your email is already up to date"). The signature
        // covers the parameter, so it cannot be pointed at another database.
        $workspaceId = $request->query('ws');
        $workspaceId = is_numeric($workspaceId) ? (int) $workspaceId : null;

        return app(WorkspaceManager::class)->runFor(
            $workspaceId,
            fn (): RedirectResponse => $this->verify($id, $hash),
        );
    }

    private function verify(int $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if ($user === null) {
            throw new HttpException(404, 'User not found.');
        }

        $pending = (string) ($user->new_email ?? '');

        if ($pending === '') {
            // Nothing to verify — either already applied or never requested.
            // Treat as success rather than 4xx so users clicking a stale
            // link after a successful verification don't see a scary error.
            return redirect()->route('profile')->with('flash', (string) __('Your email is already up to date.'));
        }

        if (! hash_equals(VerifyNewEmail::hashFor($pending), $hash)) {
            // User has since requested a DIFFERENT new email — this URL
            // refers to an obsolete pending value. Refuse the swap.
            throw new HttpException(403, 'This verification link is no longer valid because a different email change is pending.');
        }

        // Use forceFill so the datetime cast handles the Carbon → string
        // serialisation without PHPStan tripping on the typed-property
        // mismatch (the User model lists `email_verified_at` as string|null
        // by way of Larastan's schema inference; the cast does the rest).
        $user->forceFill([
            'email' => $pending,
            'new_email' => null,
            'email_verified_at' => now(),
        ])->save();

        return redirect()->route('profile')->with('flash', (string) __('Email updated. You can now sign in with :email.', ['email' => $pending]));
    }
}

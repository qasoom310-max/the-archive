<?php

declare(strict_types=1);

namespace App\Erp\Security;

use App\Models\AdminOtpChallenge;
use App\Models\User;
use App\Notifications\AdminActionOtp;
use Illuminate\Support\Carbon;

/**
 * Email one-time-code gate for sensitive admin actions (deleting/editing a
 * user or a database). A REGULAR admin must confirm a 6-digit code emailed to
 * them; the SUPER admin is exempt and acts directly.
 *
 * The code is stored hashed (SHA-256) with a short TTL and consumed on a
 * successful verify, so a code can't be replayed.
 */
final class TwoFactorGate
{
    private const TTL_MINUTES = 10;

    /**
     * Whether this user must pass the email-OTP step. Super admins are exempt;
     * non-admins never reach the gated actions in the first place.
     */
    public function required(User $user): bool
    {
        return $user->isAdmin() && ! $user->isSuperAdmin();
    }

    /**
     * Generate, store (hashed) and email a fresh code for (user, action),
     * replacing any previous live challenge for the same pair.
     */
    public function challenge(User $user, string $action): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        AdminOtpChallenge::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'action' => $action],
            ['code_hash' => hash('sha256', $code), 'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES)],
        );

        // Sent synchronously (no queue) so the code arrives immediately — the
        // Hostinger queue only drains once a minute, which would defeat a 2FA
        // prompt the admin is staring at.
        $user->notify(new AdminActionOtp($code, $action));
    }

    /**
     * Validate a submitted code for (user, action). Consumes the challenge on
     * success so it can't be reused. Expired or mismatched codes return false.
     */
    public function verify(User $user, string $action, string $code): bool
    {
        $challenge = AdminOtpChallenge::query()
            ->where('user_id', $user->getKey())
            ->where('action', $action)
            ->first();

        if ($challenge === null || $challenge->expires_at->isPast()) {
            return false;
        }

        $matches = hash_equals($challenge->code_hash, hash('sha256', trim($code)));

        if ($matches) {
            $challenge->delete();
        }

        return $matches;
    }
}

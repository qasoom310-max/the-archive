<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent to the user's NEW (pending) email address after they request an
 * email change from the profile page. The link is a 1-hour signed URL
 * carrying the user id + a `hash` of the pending email — the controller
 * re-derives the same hash and refuses to swap if it doesn't match
 * (defends against an attacker tampering with the URL or a stale link
 * fired after the user picked a different new_email).
 *
 * Dispatched via `Notification::route('mail', $newEmail)->notify(...)`
 * so the message lands at the pending address rather than the current
 * (already-verified) `users.email` — see ProfilePage::save().
 */
final class VerifyNewEmail extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $userId,
        private readonly string $newEmail,
        private readonly string $name,
    ) {
    }

    public static function for(User $user, string $newEmail): self
    {
        return new self($user->getKey(), $newEmail, $user->name);
    }

    /**
     * @return list<string>
     */
    public function via(AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'profile.email.verify',
            now()->addHour(),
            [
                'id' => $this->userId,
                'hash' => self::hashFor($this->newEmail),
            ],
        );

        return (new MailMessage())
            ->subject('Confirm your new email address')
            ->greeting('Hi ' . $this->name . ',')
            ->line('You requested to change your email to **' . $this->newEmail . '**.')
            ->line('Click the button below to confirm. This link expires in 1 hour.')
            ->action('Confirm email change', $url)
            ->line('If you didn\'t request this change, you can safely ignore this email — your current address stays in place.');
    }

    /**
     * Stable hash for a candidate email. Matches what the controller
     * recomputes from `new_email` at click-time. Uses sha256 of the
     * lowercased email + the app key so two users requesting the same
     * email can't trade signed URLs.
     */
    public static function hashFor(string $email): string
    {
        $key = (string) config('app.key');

        return hash('sha256', strtolower(trim($email)) . '|' . $key);
    }
}

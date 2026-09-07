<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "set a new password" link emailed to someone who has locked themselves
 * out. Deliberately NOT queued — a person is sitting at the sign-in screen
 * waiting for it, and the queue only drains when the host's minute cron fires
 * ({@see \App\Erp\Security\TwoFactorGate} sends its code synchronously for the
 * same reason).
 *
 * The link carries the email alongside the token so the reset screen knows
 * which account it is setting a password for without asking again — the token
 * alone is meaningless, and the broker checks the pair.
 */
final class ResetPasswordLink extends Notification
{
    public function __construct(public readonly string $token)
    {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? (string) $notifiable->getEmailForPasswordReset()
            : '';

        $url = route('password.reset', ['token' => $this->token, 'email' => $email]);

        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage())
            ->subject(__('Reset your password'))
            ->line(__('We received a request to reset the password for your account.'))
            ->action(__('Set a new password'), $url)
            ->line(__('This link expires in :count minutes.', ['count' => $minutes]))
            ->line(__('If you did not ask for this, nothing has changed — you can ignore this email.'));
    }
}

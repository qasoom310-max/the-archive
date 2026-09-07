<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Erp\Settings\Setting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * The welcome mail for an account an admin has just created: where to sign
 * in, the email to sign in with, and the password the system generated for
 * them — plus the way to swap it for one of their own.
 *
 * Sent on demand (`Notification::route('mail', …)`) rather than to a User
 * model, because the account may have just been written into a different
 * database from the one the admin is standing in; only the address matters.
 * Sent synchronously — the queue only drains on the host's minute cron, and
 * a password that arrives tomorrow is a support call today.
 *
 * The "choose your own" link goes to the forgot-password screen, NOT a reset
 * token: a token dies after 60 minutes, and a welcome mail is often opened
 * days later.
 */
final class WelcomeCredentials extends Notification
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        #[\SensitiveParameter] public readonly string $password,
    ) {
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
        $business = $this->businessName();

        return (new MailMessage())
            ->subject(__('Your :business account', ['business' => $business]))
            ->greeting(__('Hello :name,', ['name' => $this->name]))
            ->line(__('An account has been created for you on :business. Sign in with:', ['business' => $business]))
            ->line(__('Email: :email', ['email' => $this->email]))
            ->line(__('Password: :password', ['password' => $this->password]))
            ->action(__('Sign in'), route('login'))
            ->line(__('Prefer a password of your own? Use “Forgot your password?” on the sign-in page and we will email you a link to set one:'))
            ->line(route('password.request'))
            ->line(__('Keep this email private — anyone with this password can sign in as you.'));
    }

    /** The database's own company name, falling back to the app name. */
    private function businessName(): string
    {
        try {
            $name = Setting::get('company.name');
        } catch (Throwable) {
            $name = null;
        }

        return is_string($name) && trim($name) !== '' ? trim($name) : (string) config('app.name', 'OpenERP');
    }
}

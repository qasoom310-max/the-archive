<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one plain-text email through the configured mail transport and
 * reports exactly what happened — which mailer it used, and either "sent"
 * or the underlying transport exception.
 *
 * Exists because a mail send can succeed from the app's point of view (no
 * exception, the UI shows success) while nothing is actually delivered —
 * most commonly because MAIL_MAILER is missing/blank in .env, which makes
 * Laravel silently fall back to the "log" driver (see config/mail.php):
 * mail is then just written to storage/logs/laravel.log, never sent. This
 * command surfaces that distinction directly instead of trawling logs.
 *
 *   php artisan mail:test someone@example.com
 */
final class MailTestCommand extends Command
{
    protected $signature = 'mail:test {email : Address to send the test message to}';

    protected $description = 'Send a one-off test email and report the mailer used and whether it actually sent.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("\"{$email}\" is not a valid email address.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $this->info("Mailer (MAIL_MAILER): {$mailer}");

        if ($mailer === 'log' || $mailer === 'array') {
            $this->warn('This mailer does NOT deliver anything — it only writes to storage/logs/laravel.log (or holds it in memory). Set MAIL_MAILER=smtp in .env to actually send mail.');
        }

        if ($mailer === 'smtp') {
            $this->line('Host: ' . (string) config('mail.mailers.smtp.host'));
            $this->line('Port: ' . (string) config('mail.mailers.smtp.port'));
            $this->line('Username: ' . (string) config('mail.mailers.smtp.username'));
        }

        try {
            Mail::raw(
                'This is a test message from ' . (string) config('app.name') . ', sent ' . now()->toDateTimeString() . '. If it arrived, outbound mail is working.',
                function (Message $message) use ($email): void {
                    $message->to($email)->subject('OpenERP mail test');
                },
            );
        } catch (Throwable $e) {
            $this->error('FAILED: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Handed off to the \"{$mailer}\" mailer with no error.");

        if ($mailer !== 'log' && $mailer !== 'array') {
            $this->info('If it does not arrive within a few minutes: check spam, and check the mailbox is not full or blocked.');
        }

        return self::SUCCESS;
    }
}

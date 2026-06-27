<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Create or repair an admin login (idempotent), for recovery when everyone is
 * locked out. With no email/password it does nothing — so it's safe to leave in
 * the deploy chain (a no-op until the deploy passes the values from a secret).
 *
 *   php artisan admin:ensure you@example.com 'a-strong-pass'
 */
final class EnsureAdminCommand extends Command
{
    protected $signature = 'admin:ensure {email? : Admin email} {password? : Plain password (min 8)} {--name=Admin}';

    protected $description = 'Create or reset an admin login (idempotent); no-ops without credentials.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $password = (string) $this->argument('password');

        if ($email === '' || $password === '') {
            $this->info('admin:ensure skipped — no credentials provided.');

            return self::SUCCESS;
        }

        if (mb_strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) ($this->option('name') ?: 'Admin'),
                'is_admin' => true,
                'password' => Hash::make($password),
            ],
        );

        $this->info("Admin ensured: {$user->email} (id {$user->getKey()}).");

        return self::SUCCESS;
    }
}

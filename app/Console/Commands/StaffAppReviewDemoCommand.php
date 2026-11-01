<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Limousine\Services\AppReviewDemo;

/**
 * The App Store / Google Play reviewers' sign-in: a made-up business with
 * invented trips, and an account locked to it ({@see AppReviewDemo}).
 *
 *   php artisan staff-app:review-demo                      create it (prints the password once)
 *   php artisan staff-app:review-demo --password='…'       create it, or set the reviewer's password
 *   php artisan staff-app:review-demo --refresh            put the trips back (scheduled; no-op without a demo)
 */
final class StaffAppReviewDemoCommand extends Command
{
    protected $signature = 'staff-app:review-demo
        {--password= : The reviewer\'s password (min 8); a new one is made on first creation}
        {--refresh : Only re-date the demo trips, if the demo business exists}';

    protected $description = 'Create or refresh the made-up business the app-store reviewers sign in to.';

    public function handle(AppReviewDemo $demo): int
    {
        if ($this->option('refresh') === true) {
            $workspace = $demo->workspace();
            if ($workspace !== null) {
                $demo->refresh($workspace);
            }

            return self::SUCCESS;
        }

        $password = trim((string) $this->option('password'));
        if ($password !== '' && mb_strlen($password) < 8) {
            $this->error('The password must be at least 8 characters.');

            return self::FAILURE;
        }

        $created = $demo->workspace() === null;
        // A reviewer without a password could never sign in: make one.
        if ($password === '' && ($created || ! $demo->reviewerExists())) {
            $password = Str::password(16, symbols: false);
        }

        $workspace = $demo->ensure($password === '' ? null : $password);

        $this->info(($created ? 'Created' : 'Refreshed') . " \"{$workspace->name}\" (database #{$workspace->id}).");
        $this->line('Reviewer sign-in: ' . AppReviewDemo::LOGIN);
        if ($password !== '') {
            $this->line("Password: {$password}");
        }

        return self::SUCCESS;
    }
}

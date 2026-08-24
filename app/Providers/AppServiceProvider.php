<?php

declare(strict_types=1);

namespace App\Providers;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Notifications\NotificationCenter;
use App\Erp\Settings\CompanyTimezone;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\Translatable\Translatable;
use Throwable;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingManager::class);
        $this->app->singleton(ActivityLogger::class);
        // Shared across the request so module service providers register their
        // notification providers into the one instance the bell reads.
        $this->app->singleton(NotificationCenter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure Spatie's translation fallback so a model with a
        // value only in one locale still renders cleanly in the other.
        // Default: try the configured fallback locale; if that's missing
        // too, fall back to ANY translation we have (so an Arabic-only
        // product imported into an English-language UI displays its
        // Arabic name rather than a blank row). The package's default
        // is fallback_any=false → blank cells, which surprises users.
        $translatable = $this->app->make(Translatable::class);
        $translatable->fallbackLocale = (string) config('app.fallback_locale', 'en');
        $translatable->fallbackAny = true;

        $this->applyConfiguredTimezone();
        $this->registerActivityListeners();
    }

    /**
     * Audit-trail the authentication lifecycle. The Login/Logout events carry
     * the user explicitly (Auth::user() may already be cleared on logout), so
     * we pass it through. Failed sign-ins record the attempted identifier.
     */
    private function registerActivityListeners(): void
    {
        $logger = $this->app->make(ActivityLogger::class);

        Event::listen(Login::class, static function (Login $event) use ($logger): void {
            $logger->log('login', actor: $event->user);
        });

        Event::listen(Logout::class, static function (Logout $event) use ($logger): void {
            $logger->log('logout', actor: $event->user);
        });

        Event::listen(Failed::class, static function (Failed $event) use ($logger): void {
            $credentials = $event->credentials;
            $identifier = $credentials['email'] ?? $credentials['name'] ?? 'unknown';
            $logger->log('login_failed', is_string($identifier) ? $identifier : 'unknown');
        });
    }

    /**
     * Apply the active database's configured timezone. Delegates to
     * {@see CompanyTimezone}, which is applied AGAIN after a workspace swap so
     * a tenant never runs on Main's timezone.
     */
    private function applyConfiguredTimezone(): void
    {
        CompanyTimezone::apply();
    }
}

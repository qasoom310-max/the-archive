<?php

declare(strict_types=1);

namespace App\Providers;

use App\Erp\Settings\SettingManager;
use Illuminate\Support\ServiceProvider;
use Spatie\Translatable\Translatable;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingManager::class);
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
    }
}
